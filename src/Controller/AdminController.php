<?php
namespace App\Controller;
use App\Service\AccountActivator;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\Cv;
use App\Entity\Recruteur;
use App\Entity\TemplateCv;
use App\Entity\TemplateFicheCompetence;
use App\Form\ModifierRecruteur;
use App\Form\RecruteurType;
use App\Form\TemplateCvType;
use App\Form\TemplateFicheCompetenceType;
use App\Repository\CvRepository;
use App\Repository\RecruteurRepository;
use App\Repository\TemplateCvRepository;
use App\Repository\TemplateFicheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class AdminController extends AbstractController
{

    #[Route('/admin/recruteur_liste', name: 'recruteur_liste')]
    public function recruteurListe(EntityManagerInterface $em, SessionInterface $session): Response
    {

        $recruteurs = $em->getRepository(Recruteur::class)->findAll();
        return $this->render('recruteur_liste.html.twig', [
            'recruteurs' => $recruteurs
        ]);
    }
    #[Route('/admin/ajouter_recruteur', name: 'ajouter_recruteur')]
    public function recruteurAjout(
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        UserPasswordHasherInterface $passwordHasher,
        AccountActivator $accountActivator
    ): Response {
        $recruteur = new Recruteur();
        $form = $this->createForm(RecruteurType::class, $recruteur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Récupérer le mot de passe depuis le formulaire
            $password = $form->get('password')->getData();

            // Hachage du mot de passe
            $hashedPassword = $passwordHasher->hashPassword(
                $recruteur,
                $password
            );

            // Assigner le mot de passe haché à l'objet recruteur
            $recruteur->setPassword($hashedPassword);
            $recruteur->setIsActive(false);

            // Générer un token d'activation unique
            $activationToken = bin2hex(random_bytes(8));
            $recruteur->setActivationToken($activationToken);

            try {
                // Envoi de l'email d'activation avec le mot de passe en clair et le token
                $accountActivator->sendActivationEmail($recruteur, $password);  // Envoi du mot de passe en clair et du token

                // Persister l'entité recruteur dans la base de données
                $em->persist($recruteur);
                $em->flush();  // Sauvegarder les modifications (y compris le token)

                $this->addFlash('success', 'Recruteur créé. Un mail d’activation a été envoyé.');

                // Redirection après le succès du traitement
                return $this->redirectToRoute('recruteur_liste');  // Redirection vers la liste des recruteurs

            } catch (\Exception $e) {
                $logger->error('Erreur envoi email: '.$e->getMessage());
                $this->addFlash('error', "L'enregistrement a réussi mais l'email n'a pas pu être envoyé.");
            }
        }

        return $this->render('ajoutereecruteur.html.twig', [
            'form' => $form->createView()
        ]);
    }




    #[Route('/admin/modifier_recruteur/{id}', name: 'recruteur_modifier')]
    public function modifierRecruteur($id, Request $request, EntityManagerInterface $em): Response
    {
        $recruteur = $em->getRepository(Recruteur::class)->find($id);
        if (!$recruteur) {
            throw $this->createNotFoundException('Recruteur non trouvé.');
        }
        $form = $this->createForm(ModifierRecruteur::class, $recruteur);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $ancienPassword = $form->get('ancien_password')->getData();
            $nouveauPassword = $form->get('nouveau_password')->getData();
            if ($ancienPassword && $ancienPassword !== $recruteur->getPassword()) {
                $this->addFlash('error', 'L\'ancien mot de passe est incorrect.');
                return $this->redirectToRoute('recruteur_modifier', ['id' => $id]);
            }
            if ($nouveauPassword) {
                $recruteur->setPassword($nouveauPassword);
            }
            $em->flush();
            $this->addFlash('success', 'Les informations ont été mises à jour avec succès.');
            return $this->redirectToRoute('recruteur_liste');
        }
        return $this->render('recruteur_modifier.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    #[Route('/admin/recruteur_supprimer/{id}', name: 'recruteur_supprimer')]
    public function recruteurSupprimer($id, EntityManagerInterface $em): Response
    {
        $recruteur = $em->getRepository(Recruteur::class)->find($id);
        if ($recruteur) {
            $em->remove($recruteur);
            $em->flush();
        }
        return $this->redirectToRoute('recruteur_liste');
    }

    #[Route('/admin', name: 'admin_dashboard')]
    public function dashboard(
        TemplateCvRepository $cvTemplateRepo,
        TemplateFicheRepository $ficheRepo,
        RecruteurRepository  $recruteurRepo
    ): Response {
        return $this->render('admin.html.twig', [
            'cv_templates_count' => $cvTemplateRepo->count([]),
            'fiches_count' => $ficheRepo->count([]),
            'recruteursCount' => $recruteurRepo->count([]),
        ]);
    }
    #[Route('/admin/template/add', name: 'admin_add_template')]
    public function uploadTemplate(Request $request, EntityManagerInterface $em, TemplateCvRepository $templateCvRepository): Response
    {
        $templatecv = new TemplateCv();
        $form = $this->createForm(TemplateCvType::class, $templatecv);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/cvs';
            $file = $form->get('filePath')->getData();
            if ($file) {
                $error = $this->handleFileUpload($file, ['pdf', 'docx', 'txt'], 2 * 1024 * 1024, 'filePath');
                if ($error) {
                    $this->addFlash('error', $error);
                    return $this->redirectToRoute('admin_add_template');
                }
                $newFilename = uniqid() . '.' . $file->guessExtension();
                $filePath = $uploadsDirectory . '/' . $newFilename;
                try {
                    $file->move($uploadsDirectory, $newFilename);
                    $command = escapeshellcmd("python " . $this->getParameter('kernel.project_dir') . "/public/convert_pdf_to_html.py " . escapeshellarg($filePath));
                    $htmlOutput = shell_exec($command . " 2>&1");
                    if (!$htmlOutput || empty($htmlOutput)) {
                        throw new \Exception('Erreur lors de la conversion du fichier.');
                    }
                    $templatecv->setFilePath('/uploads/cvs/' . $newFilename);
                    $templatecv->setHtmlContent($htmlOutput);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléversement ou de la conversion du fichier : ' . $e->getMessage());
                    return $this->redirectToRoute('admin_add_template');
                }
            }
            $logoFile = $form->get('logo')->getData();
            if ($logoFile) {
                $logoUploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                if (!is_dir($logoUploadsDirectory)) {
                    mkdir($logoUploadsDirectory, 0777, true);
                }
                $logoFilename = uniqid() . '.' . $logoFile->guessExtension();
                try {
                    $logoFile->move($logoUploadsDirectory, $logoFilename);
                    $templatecv->setLogo('/uploads/logos/' . $logoFilename);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléversement du logo : ' . $e->getMessage());
                    return $this->redirectToRoute('admin_add_template');
                }
            }
            $em->persist($templatecv);
            $em->flush();
            $this->addFlash('success', 'Template ajouté avec succès !');
            return $this->redirectToRoute('admin_add_template'); // Redirection après succès
        }
        $templates = $templateCvRepository->findAll();
        return $this->render('admin/add_template.html.twig', [
            'form' => $form->createView(),
            'templates' => $templates,
        ]);
    }
    private function handleFileUpload($file, array $allowedExtensions, int $maxSize, string $fieldName): ?string
    {
        $extension = $file->guessExtension();
        if (!in_array($extension, $allowedExtensions)) {
            return "Le fichier du champ {$fieldName} doit être au format : " . implode(', ', $allowedExtensions) . ".";
        }
        if ($file->getSize() > $maxSize) {
            return "Le fichier du champ {$fieldName} ne doit pas dépasser " . ($maxSize / 1024 / 1024) . " Mo.";
        }
        return null;
    }
    #[Route('/admin/fiche/add', name: 'admin_add_fiche', methods: ['GET', 'POST'])]
    public function addFiche(Request $request, EntityManagerInterface $em, TemplateFicheRepository $templateFicheRepository): Response
    {
        $ficheCompetence = new TemplateFicheCompetence();
        $form = $this->createForm(TemplateFicheCompetenceType::class, $ficheCompetence);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('filePath')->getData();
            if ($file) {
                $allowedExtensions = ['pdf', 'docx', 'txt'];
                $fileExtension = $file->guessExtension();
                if (!in_array($fileExtension, $allowedExtensions)) {
                    $this->addFlash('error', 'Format non autorisé ! Formats acceptés : PDF, DOCX, TXT.');
                    return $this->redirectToRoute('admin_add_fiche');
                }
                if ($file->getSize() > 2 * 1024 * 1024) {
                    $this->addFlash('error', 'Fichier trop volumineux (max 2MB).');
                    return $this->redirectToRoute('admin_add_fiche');
                }
                $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/fiches';
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                $newFilename = uniqid() . '.' . $fileExtension;
                try {
                    $file->move($uploadsDirectory, $newFilename);
                    $ficheCompetence->setFilePath('/uploads/fiches/' . $newFilename);
                    $filePath = $uploadsDirectory . '/' . $newFilename;
                    $command = escapeshellcmd("python " . $this->getParameter('kernel.project_dir') . "/public/convert_pdf_to_html.py " . escapeshellarg($filePath));
                    $htmlOutput = shell_exec($command . " 2>&1");
                    if (!$htmlOutput) {
                        $this->addFlash('error', 'Erreur lors de la conversion du fichier.');
                        return $this->redirectToRoute('admin_add_fiche');
                    }
                    $ficheCompetence->setHtmlContent($htmlOutput);
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors du téléversement du fichier.');
                    return $this->redirectToRoute('admin_add_fiche');
                }
                $em->persist($ficheCompetence);
                $em->flush();
                $this->addFlash('success', 'Fiche de compétence ajoutée avec succès.');
                return $this->redirectToRoute('admin_dashboard');
            }
        }
        $templates = $templateFicheRepository->findAll();
        return $this->render('admin/add_fiche.html.twig', [
            'form' => $form->createView(),
            'templates' => $templates,
        ]);
    }
    #[Route('/admin/template/edit/{id}', name: 'admin_edit_template', methods: ['GET', 'POST'])]
    public function modifierTemplateCv($id, Request $request, EntityManagerInterface $em): Response
    {
        $templatecv = $em->getRepository(TemplateCv::class)->find($id);
        if (!$templatecv) {
            throw $this->createNotFoundException('Template CV non trouvé.');
        }
        $form = $this->createForm(TemplateCvType::class, $templatecv);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('filePath')->getData();
            if ($file) {
                $oldFilePath = $this->getParameter('kernel.project_dir') . '/public' . $templatecv->getFilePath();
                if ($templatecv->getFilePath() && file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
                $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/cvs';
                if (!file_exists($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                $newFilename = uniqid() . '.' . $file->guessExtension();
                $filePath = $uploadsDirectory . '/' . $newFilename;

                try {
                    $file->move($uploadsDirectory, $newFilename);
                    $command = escapeshellcmd("python " . $this->getParameter('kernel.project_dir') . "/public/convert_pdf_to_html.py " . escapeshellarg($filePath));
                    $htmlOutput = shell_exec($command . " 2>&1");

                    if (!$htmlOutput || empty($htmlOutput)) {
                        throw new \Exception('Erreur lors de la conversion du fichier.');
                    }
                    $templatecv->setFilePath('/uploads/cvs/' . $newFilename);
                    $templatecv->setHtmlContent($htmlOutput);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement ou de la conversion du fichier : ' . $e->getMessage());
                    return $this->redirectToRoute('admin_edit_template', ['id' => $id]);
                }
            }
            $logoFile = $form->get('logo')->getData();
            if ($logoFile) {
                $oldLogoPath = $this->getParameter('kernel.project_dir') . '/public' . $templatecv->getLogo();
                if ($templatecv->getLogo() && file_exists($oldLogoPath)) {
                    unlink($oldLogoPath);
                }
                $uploadsLogoDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                if (!file_exists($uploadsLogoDirectory)) {
                    mkdir($uploadsLogoDirectory, 0777, true);
                }
                $newLogoname = uniqid() . '.' . $logoFile->guessExtension();
                try {
                    $logoFile->move($uploadsLogoDirectory, $newLogoname);
                    $templatecv->setLogo('/uploads/logos/' . $newLogoname);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement du logo.');
                    return $this->redirectToRoute('admin_edit_template', ['id' => $id]);
                }
            }

            $em->persist($templatecv);
            $em->flush();
            $this->addFlash('success', 'Template CV mis à jour avec succès.');
            return $this->redirectToRoute('admin_dashboard');
        }
        return $this->render('admin/edit_template.html.twig', [
            'form' => $form->createView(),
            'template' => $templatecv,
        ]);
    }

    #[Route('/admin/template/delete/{id}', name: 'admin_delete_template')]
    public function supprimerTemplateCv($id, EntityManagerInterface $em): Response
    {
        $templatecv = $em->getRepository(TemplateCv::class)->find($id);
        if (!$templatecv) {
            throw $this->createNotFoundException('Template CV non trouvé.');
        }
        $filePath = $this->getParameter('kernel.project_dir') . '/public/' . $templatecv->getFilePath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $em->remove($templatecv);
        $em->flush();

        $this->addFlash('success', 'Template CV supprimé avec succès.');
        return $this->redirectToRoute('admin_dashboard');
    }
    #[Route('/admin/fiche/delete/{id}', name: 'admin_delete_fiche')]
    public function supprimerTemplateFiche($id, EntityManagerInterface $em): Response
    {
        $ficheCompetence = $em->getRepository(TemplateFicheCompetence::class)->find($id);
        if (!$ficheCompetence) {
            throw $this->createNotFoundException('Fiche de compétence non trouvée.');
        }
        $filePath = $this->getParameter('kernel.project_dir') . '/public/' . $ficheCompetence->getFilePath();
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        $em->remove($ficheCompetence);
        $em->flush();
        $this->addFlash('success', 'Fiche de compétence supprimée avec succès.');
        return $this->redirectToRoute('admin_dashboard');
    }
    #[Route('/admin/fiche/edit/{id}', name: 'admin_edit_fiche', methods: ['GET', 'POST'])]
    public function editFiche(Request $request, EntityManagerInterface $em, TemplateFicheRepository $templateFicheRepository, int $id): Response
    {
        $templatefiche = $em->getRepository(TemplateFicheCompetence::class)->find($id);
        if (!$templatefiche) {
            throw $this->createNotFoundException('Template fiche non trouvé.');
        }

        $form = $this->createForm(TemplateFicheCompetenceType::class, $templatefiche);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('filePath')->getData();
            if ($file) {
                $oldFilePath = $this->getParameter('kernel.project_dir') . '/public' . $templatefiche->getFilePath();
                if ($templatefiche->getFilePath() && file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
                $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/fiches';
                if (!file_exists($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                $newFilename = uniqid() . '.' . $file->guessExtension();
                $filePath = $uploadsDirectory . '/' . $newFilename;
                try {
                    $file->move($uploadsDirectory, $newFilename);
                    $templatefiche->setFilePath('/uploads/fiches/' . $newFilename);
                    $command = escapeshellcmd("python " . $this->getParameter('kernel.project_dir') . "/public/convert_pdf_to_html.py " . escapeshellarg($filePath));
                    $htmlOutput = shell_exec($command . " 2>&1");

                    if (!$htmlOutput || empty($htmlOutput)) {
                        throw new \Exception('Erreur lors de la conversion du fichier.');
                    }
                    $templatefiche->setHtmlContent($htmlOutput);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement ou de la conversion du fichier : ' . $e->getMessage());
                    return $this->redirectToRoute('admin_edit_fiche', ['id' => $id]);
                }
            }
            $logoFile = $form->get('logo')->getData();
            if ($logoFile) {
                $oldLogoPath = $this->getParameter('kernel.project_dir') . '/public' . $templatefiche->getLogo();
                if ($templatefiche->getLogo() && file_exists($oldLogoPath)) {
                    unlink($oldLogoPath);
                }
                $uploadsLogoDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                if (!file_exists($uploadsLogoDirectory)) {
                    mkdir($uploadsLogoDirectory, 0777, true);
                }
                $newLogoname = uniqid() . '.' . $logoFile->guessExtension();
                try {
                    $logoFile->move($uploadsLogoDirectory, $newLogoname);
                    $templatefiche->setLogo('/uploads/logos/' . $newLogoname);
                } catch (\Exception $e) {
                    $this->addFlash('error', 'Erreur lors du téléchargement du logo.');
                    return $this->redirectToRoute('admin_edit_fiche', ['id' => $id]);
                }
            }
            $em->persist($templatefiche);
            $em->flush();
            $this->addFlash('success', 'Fiche de compétence modifiée avec succès.');
            return $this->redirectToRoute('admin_dashboard');
        }
        return $this->render('admin/edit_fiche.html.twig', [
            'form' => $form->createView(),
            'fiche' => $templatefiche,
        ]);
    }



}