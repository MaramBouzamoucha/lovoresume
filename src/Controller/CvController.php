<?php
namespace App\Controller;
use App\Service\GoogleFormsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\Cv;
use App\Form\CvType;
use App\Repository\TemplateCvRepository;
use App\Repository\TemplateFicheRepository;
use App\Service\OpenAIChatService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Smalot\PdfParser\Parser;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Environment;
class CvController extends AbstractController
{
    private string $cvGenererDirectory;
    private $questionGenerationService;
    private $googleFormsService;

    public function __construct(string $cvGenererDirectory,OpenAIChatService $questionGenerationService,GoogleFormsService $googleFormsService)
    {
        $this->cvGenererDirectory = $cvGenererDirectory;
        $this->questionGenerationService = $questionGenerationService;
        $this->googleFormsService = $googleFormsService;


    }

    #[Route('/cv/upload', name: 'cv_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $cv = new Cv();
        $form = $this->createForm(CvType::class, $cv);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $cvFile = $form->get('cvFile')->getData();
            if ($cvFile) {
                $originalFilename = pathinfo($cvFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $cvFile->guessExtension();
                try {
                    $cvFile->move(
                        $this->getParameter('cv_directory'),
                        $newFilename
                    );
                } catch (FileException) {
                    $this->addFlash('error', 'Une erreur s\'est produite lors de l\'upload du fichier.');
                    return $this->redirectToRoute('cv_upload');
                }
                $scriptPath = $this->getParameter('kernel.project_dir') . '/public/extract_pdf_data.py';
                $filePath = $this->getParameter('cv_directory') . '/' . $newFilename;
                $process = new Process(['python', $scriptPath, $filePath]);
                $process->run();
                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }
                $output = json_decode($process->getOutput(), true);
                $textExtracted = $output['text'] ?? 'Erreur lors de l\'extraction';
                $cv->setCv($newFilename);
                $cv->setTexteExtrait($textExtracted);
                $entityManager->persist($cv);
                $entityManager->flush();
                $this->addFlash('success', 'Le CV a été uploadé et analysé avec succès.');
                return $this->redirectToRoute('cvs');
            }
        }
        return $this->render('upload.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/cvs',name: 'cvs')]
    public function lists(EntityManagerInterface $entityManager, TemplateCvRepository $templateRepository, TemplateFicheRepository $templateFicheRepository,Environment $twig): Response
    {
        $cvs = $entityManager->getRepository(Cv::class)->findAll();
        $cvTemplates = $templateRepository->findAll();
        $ficheTemplates = $templateFicheRepository->findAll();


        return new Response($twig->render('listCV.html.twig', [
            'cvs' => $cvs,
            'cvTemplates' => $cvTemplates,
            'ficheCompetencesTemplates' => $ficheTemplates,
        ]));
    }
    #[Route('/cv/delete/{id}', name: 'cv_delete', methods: ['POST'])]
    public function delete(Request $request, Cv $cv, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $cv->getId(), $request->request->get('_token'))) {
            $filePath = $this->getParameter('cv_directory') . '/' . $cv->getCv();
            if ($cv->getCv() && file_exists($filePath)) {
                unlink($filePath);
            }
            $entityManager->remove($cv);
            $entityManager->flush();
            $this->addFlash('success', 'Le CV a été supprimé avec succès.');
        }
        return $this->redirectToRoute('cvs');
    }
    #[Route('/cv/generate/{id}', name: 'cv_generate', methods: ['POST'])]
    public function generateCv(Request $request, int $id,LoggerInterface $logger): Response
    {
        $logger->info('Tentative de génération', [
            'id' => $id,
            'data' => $request->request->all()
        ]);
        try {
            $template = $request->request->get('template');
            if (empty($template)) {
                throw new RuntimeException('Aucun template sélectionné');
            }
            return $this->redirectToRoute('cv_template_render', [
                'id' => $id,
                'template' => $template
            ]);
        } catch (Exception $e) {
            $logger->error('Erreur génération', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'Erreur lors de la génération');
            return $this->redirectToRoute('cv_list');
        }
    }
    #[Route('/cv/render/{id}/{template}', name: 'cv_template_render')]
    public function renderCv(
        int $id,
        string $template,
        EntityManagerInterface $entityManager,
        TemplateCvRepository $templateCvRepository,
        OpenAIChatService $openAIChatService,
        TwigEnvironment $twig,
        RequestStack $requestStack
    ): Response {

        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('CV introuvable.');
        }

        $templateCv = $templateCvRepository->findOneBy(['fileName' => $template]);
        if (!$templateCv) {
            throw $this->createNotFoundException("Template '$template' introuvable");
        }

        $generatedHtml = $openAIChatService->generateCvFromTemplate(
            $cv->getTexteExtrait(),
            $templateCv->getHtmlContent()
        );


        $requestStack->getSession()->set('generated_html_'.$id, $generatedHtml);


        return new Response($twig->render('default.html.twig', [
            'cv' => $cv,
            'generatedHtml' => $generatedHtml
        ]));
    }

    #[Route('/cv/export/{id}', name: 'cv_export_pdf', methods: ['GET'])]
    public function exportPdf(
        int $id,
        RequestStack $requestStack,
        EntityManagerInterface $entityManager,
        Environment $twig
    ): Response
    {
        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('CV introuvable.');
        }
        $generatedHtml = $requestStack->getSession()->get('generated_html_'.$id);
        if (!$generatedHtml) {
            throw new \RuntimeException('Aucun contenu HTML disponible pour la génération');
        }
        $generatedHtml = preg_replace('/<img[^>]*src=["\'].*LOGO\.png["\'][^>]*>/i', '', $generatedHtml);
        $contactInfo = '';
        if (preg_match('/<div class="contact-info">(.*?)<\/div>/s', $generatedHtml, $matches)) {
            $contactInfo = $matches[0];
            $generatedHtml = preg_replace('/<div class="contact-info">.*?<\/div>/s', '', $generatedHtml);
        }
        $wrappedHtml = '<div class="cv-content">' . $generatedHtml . '</div>';
        if (!empty($contactInfo)) {
            $wrappedHtml .= $contactInfo;
        }
        $html = $twig->render('pdf_template.html.twig', [
            'generatedHtml' => $wrappedHtml,
            'cv' => $cv
        ]);
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);
        $pdfOptions->set('isHtml5ParserEnabled', true);
        $pdfOptions->set('isPhpEnabled', true);
        $pdfOptions->set('isFontSubsettingEnabled', true);
        $pdfOptions->set('dpi', 300);

        $dompdf = new Dompdf($pdfOptions);
        $dompdf->loadHtml($html);

        $dompdf->setPaper('A4', 'portrait');
        $dompdf->set_option('defaultMediaType', 'screen');
        $dompdf->set_option('defaultPaperSize', 'A4');

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);
        $dompdf->setHttpContext($context);

        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics) {
            $canvas->set_opacity(.999);
        });
        $filename = 'cv_'.$id.'_'.date('YmdHis').'.pdf';
        $output = $dompdf->output();

        $relativePath = 'uploads/cvGenere/'.$filename;

        $fullPath = $this->getParameter('kernel.project_dir').'/public/'.$relativePath;

        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }

        file_put_contents($fullPath, $output);

        $cv->setCvGenerique($filename);
        $entityManager->flush();

        return new Response(
            $output,
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"'
            ]
        );
    }
    #[Route('/skillsheet/generate/{id}', name: 'skill_sheet_generate', methods: ['POST'])]
    public function generateSkillSheet(Request $request, int $id, LoggerInterface $logger): Response
    {
        $logger->info('Génération fiche compétences', ['id' => $id, 'data' => $request->request->all()]);

        try {
            $template = $request->request->get('template');
            if (empty($template)) {
                throw new RuntimeException('Aucun template sélectionné');
            }

            return $this->redirectToRoute('skill_sheet_template_render', [
                'id' => $id,
                'template' => $template
            ]);
        } catch (Exception $e) {
            $logger->error('Erreur génération fiche compétences', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'Erreur lors de la génération');
            return $this->redirectToRoute('cvs');
        }}
        #[Route('/skillsheet/render/{id}/{template}', name: 'skill_sheet_template_render')]
        public function renderSkillSheet(
            int $id,
            string $template,
            EntityManagerInterface $entityManager,
            TemplateFicheRepository $templateFicheRepository,
            OpenAIChatService $openAIChatService,
            TwigEnvironment $twig,
            RequestStack $requestStack
        ): Response {

        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('Profil introuvable.');
        }
        $templateFiche = $templateFicheRepository->findOneBy(['fileName' => $template]);
        if (!$templateFiche) {
            throw $this->createNotFoundException("Template '$template' introuvable");
        }
        $generatedHtml = $openAIChatService->generateSkillSheetFromTemplate(
            $cv->getTexteExtrait(),
            $templateFiche->getHtmlContent()
        );
        dump($generatedHtml);
        $requestStack->getSession()->set('generated_html_skill_'.$id, $generatedHtml);
        return new Response($twig->render('defaultFiche.html.twig', [
            'cv' => $cv,
            'generatedHtml' => $generatedHtml
        ]));
    }
    #[Route('/skillsheet/export/{id}', name: 'skill_sheet_export_pdf', methods: ['GET'])]
        public function exportFichePdf(
    int $id,
    RequestStack $requestStack,
    EntityManagerInterface $entityManager,
    Environment $twig
): Response
{
    $cv = $entityManager->getRepository(Cv::class)->find($id);
    if (!$cv) {
        throw $this->createNotFoundException('CV introuvable.');
    }
    $generatedHtml = $requestStack->getSession()->get('generated_html_skill_'.$id);
    if (!$generatedHtml) {
        throw new \RuntimeException('Aucun contenu HTML disponible pour la génération');
    }
    $generatedHtml = preg_replace('/<img[^>]*src=["\'].*LOGO\.png["\'][^>]*>/i', '', $generatedHtml);
    $contactInfo = '';
    if (preg_match('/<div class="contact-info">(.*?)<\/div>/s', $generatedHtml, $matches)) {
        $contactInfo = $matches[0];
        $generatedHtml = preg_replace('/<div class="contact-info">.*?<\/div>/s', '', $generatedHtml);
    }
    $wrappedHtml = '<div class="cv-content">' . $generatedHtml . '</div>';
    if (!empty($contactInfo)) {
        $wrappedHtml .= $contactInfo;
    }
    $html = $twig->render('pdf_templateFiche.html.twig', [
        'generatedHtml' => $wrappedHtml,
        'cv' => $cv
    ]);
    $pdfOptions = new Options();
    $pdfOptions->set('defaultFont', 'Arial');
    $pdfOptions->set('isRemoteEnabled', true);
    $pdfOptions->set('isHtml5ParserEnabled', true);
    $pdfOptions->set('isPhpEnabled', true);
    $pdfOptions->set('isFontSubsettingEnabled', true);
    $pdfOptions->set('dpi', 300);

    $dompdf = new Dompdf($pdfOptions);
    $dompdf->loadHtml($html);

    $dompdf->setPaper('A4', 'portrait');
    $dompdf->set_option('defaultMediaType', 'screen');
    $dompdf->set_option('defaultPaperSize', 'A4');

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    $dompdf->setHttpContext($context);

    $dompdf->render();

    $canvas = $dompdf->getCanvas();
    $canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics) {
        $canvas->set_opacity(.999);
    });
    $filename = 'cv_'.$id.'_'.date('YmdHis').'.pdf';
    $output = $dompdf->output();

    $relativePath = 'uploads/fiches/'.$filename;

    $fullPath = $this->getParameter('kernel.project_dir').'/public/'.$relativePath;

    if (!file_exists(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0777, true);
    }

    file_put_contents($fullPath, $output);

    $cv->setFicheCompetence($filename);
    $entityManager->flush();

    return new Response(
        $output,
        200,
        [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"'
        ]
    );
}
    #[Route('/questions/generate/{id}', name: 'generate_questions', methods: ['POST'])]
    public function generateQuestions(Request $request, int $id, LoggerInterface $logger): Response
    {
        $logger->info('Début génération questions', ['cv_id' => $id]);
        try {
            $descriptionPoste = $request->request->get('descriptionPoste');
            $duration = (int)$request->request->get('duration', 30);
            if (empty($descriptionPoste)) {
                throw new \InvalidArgumentException('La description du poste est obligatoire');
            }
            $request->getSession()->set('questions_params', [
                'descriptionPoste' => $descriptionPoste,
                'duration' => $duration
            ]);
            $logger->info('Paramètres reçus', ['descriptionPoste' => $descriptionPoste, 'duration' => $duration]);
            return $this->redirectToRoute('questions_render', ['id' => $id]);
        }catch (\InvalidArgumentException $e) {
            $logger->error('Erreur génération questions', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $logger->error('Erreur génération questions', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Erreur lors de la génération des questions'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    #[Route('/questions/render/{id}', name: 'questions_render')]
    public function renderQuestions(
        int $id,
        EntityManagerInterface $entityManager,
        OpenAIChatService $openAIChatService,
        RequestStack $requestStack,
        TwigEnvironment $twig
    ): Response
    {
        try {
            $cv = $entityManager->getRepository(Cv::class)->find($id);
            if (!$cv) {
                throw $this->createNotFoundException('CV non trouvé');
            }
            $session = $requestStack->getSession();
            $params = $session->get('questions_params');
            if (empty($params)) {
                throw new \RuntimeException('Paramètres de génération non trouvés');
            }
            $cvText = $cv->getTexteExtrait();
            $generatedHtml = $openAIChatService->generateQuestions(
                $cvText,
                $params['descriptionPoste'],
                $params['duration']
            );
            $questionsJson = [
                'questions_html' => $generatedHtml,
                'description_poste' => $params['descriptionPoste'],
                'duration' => $params['duration']
            ];
            $cv->setQuestionsJson($questionsJson);
            $entityManager->flush();


            $session->set('generated_questions', $generatedHtml);
            return $this->render('QuestionsGenere.html.twig', [
                'cv' => $cv,
                'questions' => $generatedHtml,
                'descriptionPoste' => $params['descriptionPoste'],
                'duration' => $params['duration']
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'affichage des questions');
            return $this->redirectToRoute('cvs');
        }
    }
    #[Route('/questions/export-form/{id}', name: 'questions_export_form')]
    public function exportToGoogleForm(
        int $id,
        RequestStack $requestStack,
        EntityManagerInterface $entityManager,
        GoogleFormsService $googleFormsService,
        OpenAIChatService $openAIChatService,
        MailerInterface $mailer
    ): Response {
        $session = $requestStack->getSession();
        $params = $session->get('questions_params');
        $generatedHtml = $session->get('generated_questions');

        if (empty($params) || empty($generatedHtml)) {
            $this->addFlash('error', 'Aucune donnée trouvée pour générer le formulaire');
            return $this->redirectToRoute('questions_render', ['id' => $id]);
        }

        preg_match_all('/\d+\.\s+(.*?)(?=\n\d+\.|\n\n|\Z)/s', $generatedHtml, $matches);
        $questions = array_map('strip_tags', $matches[1]);


        $formUrl = $googleFormsService->createFormWithQuestions(
            'Formulaire entretien - ' . $params['descriptionPoste'],
            $questions
        );

        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('CV non trouvé');
        }

        $emailCandidat = $openAIChatService->extractEmailFromCv($cv->getTexteExtrait());

        if ($emailCandidat && filter_var($emailCandidat, FILTER_VALIDATE_EMAIL)) {
            $email = (new Email())
                ->from('maram.bouz29@gmail.com')
                ->to($emailCandidat)
                ->subject('Formulaire d\'entretien à remplir')
                ->html("
                Bonjour,<br><br>
                Veuillez remplir le formulaire suivant dans le cadre de votre candidature :<br><br>
                <a href=\"$formUrl\">Cliquez ici pour accéder au formulaire</a><br><br>
                Description du poste : <strong>{$params['descriptionPoste']}</strong><br>
                Durée estimée : <strong>{$params['duration']}</strong> minutes<br><br>
                Cordialement,<br>
                L’équipe RH
            ");

            $mailer->send($email);

            $this->addFlash('success', 'Le formulaire a été envoyé au candidat à l’adresse : ' . $emailCandidat);
        } else {
            $this->addFlash('error', 'Impossible d’extraire une adresse email valide depuis le CV.');
        }

        return $this->redirectToRoute('questions_render', ['id' => $id]);
    }


    #[Route('/questionsss/export-pdf/{id}', name: 'questions_export_pdf')]
    public function exportToPdf(
        int $id,
        RequestStack $requestStack,
        TwigEnvironment $twig,
        EntityManagerInterface $entityManager
    ): Response
    {
        $session = $requestStack->getSession();
        $params = $session->get('questions_params');
        $questions = $session->get('generated_questions');
        if (empty($params) || empty($questions)) {
            $this->addFlash('error', 'Impossible de générer le PDF : données non trouvées');
            return $this->redirectToRoute('questions_render', ['id' => $id]);
        }
        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('CV non trouvé');
        }
        $pdfOptions = new Options();
        $pdfOptions->set('defaultFont', 'Arial');
        $pdfOptions->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($pdfOptions);
        $html = $twig->render('questions_pdf.html.twig', [
            'questions' => $questions,
            'descriptionPoste' => $params['descriptionPoste'],
            'duration' => $params['duration'],
            'cv' => $cv
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="questions_entretien_'.$id.'.pdf"'
            ]
        );}
    #[Route('/questions/export-pdf/{id}', name: 'questions_export_pdf')]
    public function exportPdfQuestions(RequestStack $requestStack, MailerInterface $mailer, Environment $twig, int $id,EntityManagerInterface $entityManager,
                                       OpenAIChatService $openAIChatService): Response
    {
        $session = $requestStack->getSession();
        $params = $session->get('questions_params');
        $generatedHtml = $session->get('generated_questions');

        if (empty($generatedHtml) || empty($params)) {
            throw new \RuntimeException('Aucune question générée trouvée en session.');
        }

        preg_match_all('/\d+\.\s+(.*?)(?=\n\d+\.|\n\n|\Z)/s', $generatedHtml, $matches);
        $questions = array_map('trim', $matches[1]);

        if (empty($questions)) {
            throw new \RuntimeException('Aucune question valide trouvée dans le contenu.');
        }
        $cv = $entityManager->getRepository(Cv::class)->find($id);
        if (!$cv) {
            throw $this->createNotFoundException('CV non trouvé');
        }

        $options = new Options();
        $options->set([
            'isRemoteEnabled' => true,
            'defaultFont' => 'Arial',
            'tempDir' => sys_get_temp_dir(),
            'fontCache' => sys_get_temp_dir()
        ]);

        $dompdf = new Dompdf($options);

        $html = $twig->render('questionsPDF.html.twig', [
            'questions' => $questions,
            'descriptionPoste' => $params['descriptionPoste'],
            'duration' => $params['duration'],
        ]);


        $dompdf->loadHtml($html);
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->add_info('Title', 'Formulaire d\'entretien modifiable');
        $canvas->add_info('Creator', 'Votre Application');
        $canvas->add_info('Producer', 'Dompdf');
        $canvas->add_info('Keywords', 'PDF, Formulaire, Entretien');
        $output = $dompdf->output();
        $pdfPath = sys_get_temp_dir().'/formulaire_modifiable_'.uniqid().'.pdf';
        file_put_contents($pdfPath, $output);
        $emailCandidat = $openAIChatService->extractEmailFromCv($cv->getTexteExtrait());

        if ($emailCandidat) {
            $email = (new Email())
                ->from('maram.bouz29@gmail.com')
                ->to($emailCandidat)
                ->subject('Votre formulaire d\'entretien')
                ->text('Bonjour, veuillez trouver ci-joint le formulaire d\'entretien généré à partir de votre CV.')
                ->attachFromPath($pdfPath, 'formulaire_entretien.pdf', 'application/pdf');

            $mailer->send($email);


            $response = new BinaryFileResponse($pdfPath);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition(
                'inline',
                'formulaire_entretien.pdf'
            );}
        $response->deleteFileAfterSend(true);

        return $response;
    }
    #[Route('/evaluation/{id}', name: 'evaluate_responses')]
    public function evaluateResponses(
        Request $request,
        int $id,
        EntityManagerInterface $entityManager,
        OpenAIChatService $geminiService,
        LoggerInterface $logger
    ): Response
    {
        $logger->info("Début de l'évaluation pour le CV avec ID : {$id}");
        try {
            $cv = $entityManager->getRepository(Cv::class)->find($id);
            if (!$cv) {
                $logger->error("CV avec ID {$id} non trouvé");
                return $this->json(['error' => 'CV non trouvé'], 404);
            }
            $logger->info("CV trouvé : ID {$id}");
            $pdfFile = $request->files->get('formulaire_entretien');
            if (!$pdfFile) {
                $logger->error("Aucun fichier PDF reçu pour l'évaluation du CV ID {$id}");
                return $this->json(['error' => 'Aucun fichier PDF reçu'], 400);
            }
            $logger->info("Fichier PDF reçu : " . $pdfFile->getClientOriginalName());
            if ($pdfFile->getMimeType() !== 'application/pdf') {
                $logger->error("Le fichier reçu n'est pas un PDF pour le CV ID {$id}");
                return $this->json(['error' => 'Le fichier doit être un PDF'], 400);
            }
            $logger->info("Début de l'extraction du texte du fichier PDF");
            $pdfText = $geminiService->extractTextFromPdf($pdfFile->getPathname());
            if (empty($pdfText)) {
                $logger->error("Le PDF est vide ou illisible pour le CV ID {$id}");
                return $this->json(['error' => 'Le PDF est vide ou illisible'], 400);}
            $logger->info("Texte extrait du PDF pour le CV ID {$id} : " . substr($pdfText, 0, 100));
            $inputData = ['pdfText' => $pdfText];
            $logger->info("Envoi des données d'évaluation au service Gemini");
            $evaluation = $geminiService->generateEvaluation($inputData);
            $evaluationArray = [
                'evaluation_text' => $evaluation,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
            ];
            $cv->setEvaluationJson($evaluationArray);
            $entityManager->flush();
            $logger->info("Réponse de Gemini : " . $evaluation);
            return $this->redirectToRoute('show_result', ['id' => $id]);
        } catch (\JsonException $e) {
            $logger->error("Erreur JSON lors de l'évaluation pour le CV ID {$id}: " . $e->getMessage());
            return $this->json(['error' => 'Erreur de format de réponse: ' . $e->getMessage()], 500);
        } catch (\Doctrine\DBAL\Exception $e) {
            $logger->error("Erreur de base de données pour le CV ID {$id}: " . $e->getMessage());
            return $this->json(['error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
        } catch (\Symfony\Component\HttpFoundation\File\Exception\FileException $e) {
            $logger->error("Erreur lors du traitement du fichier pour le CV ID {$id}: " . $e->getMessage());
            return $this->json(['error' => 'Erreur de fichier: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            $logger->error("Erreur lors du traitement de l'évaluation pour le CV ID {$id}: " . $e->getMessage());
            return $this->json(['error' => 'Erreur lors du traitement: ' . $e->getMessage()], 500);
        }
    }
    #[Route('/result/{id}', name: 'show_result')]
    public function showResult(
        int $id,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ): Response
    {
        try {
            $cv = $entityManager->getRepository(Cv::class)->find($id);
            if (!$cv) {
                $logger->error("CV avec ID {$id} non trouvé");
                return $this->json(['error' => 'CV non trouvé'], 404);
            }

            $evaluationData = $cv->getEvaluationJson();

            if (!$evaluationData) {
                throw new \RuntimeException('Aucune donnée d\'évaluation trouvée');
            }

            return $this->render('result.html.twig', [
                'cv' => $cv,
                'evaluation' => $evaluationData['evaluation_text'],
                'evaluation_data' => $evaluationData
            ]);

        } catch (\Exception $e) {
            $logger->error("Erreur lors de l'affichage des résultats pour le CV ID {$id}: " . $e->getMessage());
            return $this->json(['error' => 'Erreur lors de l\'affichage: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/evaluation/export-pdf/{id}', name: 'evaluation_export_pdf')]
    public function exportEvaluationPdf(
        int $id,
        EntityManagerInterface $entityManager,
        TwigEnvironment $twig,
        LoggerInterface $logger
    ): Response
    {
        try {
            $cv = $entityManager->getRepository(Cv::class)->find($id);
            if (!$cv) {
                throw new \RuntimeException("CV non trouvé");
            }

            $evaluationData = $cv->getEvaluationJson();
            if (empty($evaluationData)) {
                throw new \RuntimeException("Aucune donnée d'évaluation trouvée");
            }

            $logger->info("Génération du PDF d'évaluation pour le CV ID {$id}");

            $options = new Options();
            $options->set([
                'isRemoteEnabled' => true,
                'defaultFont' => 'Arial',
                'tempDir' => sys_get_temp_dir(),
                'fontCache' => sys_get_temp_dir(),
            ]);

            $dompdf = new Dompdf($options);

            $html = $twig->render('evaluationPDF.html.twig', [
                'cv' => $cv,
                'evaluation' => $evaluationData['evaluation_text'],
                'date' => $evaluationData['created_at'] ?? (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
            $dompdf->loadHtml($html);
            $dompdf->render();
            $output = $dompdf->output();
            $pdfPath = sys_get_temp_dir() . '/evaluation_' . uniqid() . '.pdf';
            file_put_contents($pdfPath, $output);

            $response = new BinaryFileResponse($pdfPath);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->setContentDisposition('inline', 'evaluation.pdf');
            $response->deleteFileAfterSend(true);

            return $response;

        } catch (\Exception $e) {
            $logger->error("Erreur export PDF évaluation CV ID {$id}: " . $e->getMessage());
            return new JsonResponse(['error' => 'Erreur lors de la génération du PDF'], 500);
        }
    }

        #[Route("/google/auth/callback", name:"google_auth_callback")]

        public function callback(Request $request): Response
        {
            $code = $request->query->get('code');
            if (!$code) {
                return new Response('Authorization failed: no code provided', 400);
            }

            try {
                $client = $this->googleFormsService->getClient();
                $accessToken = $client->fetchAccessTokenWithAuthCode($code);
                file_put_contents($this->tokenPath, json_encode($accessToken));

                return new Response('Successfully authenticated with Google. You can now use the Forms API.');
            } catch (\Exception $e) {
                return new Response('Authentication failed: '.$e->getMessage(), 400);
            }
        }
    #[Route("/responses", name:"show_responses")]
    public function showResponses(): Response
    {
        $formId = 'TON_FORM_ID';
        $responses = $this->googleFormsService->getResponses($formId);

        if (is_string($responses)) {

            return $this->render('responses.html.twig', [
                'responses' => 'Erreur : ' . $responses,
            ]);
        }
        return $this->render('responses.html.twig', [
            'responses' => $responses
        ]);
    }

}