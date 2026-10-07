<?php

namespace App\Controller;
use App\Entity\Recruteur;
use App\Form\LoginType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $form = $this->createForm(LoginType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $email = $data['email'];
            $password =  $data['password'];

            $recruteur = $entityManager->getRepository(Recruteur::class)->findOneBy(['email' => $email]);

            if ($recruteur && $passwordHasher->isPasswordValid($recruteur, $password)) {
                return $this->redirectToRoute('cvs');
            }
            $this->addFlash('error', 'Email ou mot de passe incorrect');
        }
        return $this->render('login.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    #[Route('/admin/login', name: 'admin_login')]
    public function loginadmin(Request $request, SessionInterface $session): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->request->get('email');
            $password = $request->request->get('password');

            if ($email === 'admin@gmail.com' && $password === 'admin.1234') {
                $session->set('admin_logged_in', true);
                return $this->redirectToRoute('admin_dashboard');
            } else {
                $this->addFlash('error', 'Email ou mot de passe incorrect');
                return $this->redirectToRoute('admin_login');
            }
        }

        return $this->render('loginadmin.html.twig');
    }
    #[Route('/activate/{token}', name: 'app_activate_account')]
    public function activateAccount(string $token, EntityManagerInterface $em): Response
    {
        $recruteur = $em->getRepository(Recruteur::class)
            ->findOneBy(['activationToken' => $token]);

        if (!$recruteur) {
            $this->addFlash('error', 'Lien d\'activation invalide ou expiré.');
            return $this->redirectToRoute('app_login');
        }

        $recruteur->setIsActive(true);
        $recruteur->setActivationToken(null);
        $em->flush();
        $this->addFlash('success', 'Votre compte a été activé avec succès ! Vous pouvez maintenant vous connecter.');
        return $this->redirectToRoute('app_login');
    }
}
