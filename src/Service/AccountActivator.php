<?php
namespace App\Service;

use App\Entity\Recruteur;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class AccountActivator
{
    public function __construct(
        private MailerInterface       $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private Environment           $twig,
    )
    {
    }
    public function sendActivationEmail(Recruteur $recruteur, string $password): void
    {
        $activationLink = $this->urlGenerator->generate(
            'app_activate_account',
            ['token' => $recruteur->getActivationToken()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $html = $this->twig->render('activationRecruteur.html.twig', [
            'activationLink' => $activationLink,
            'recruteur' => $recruteur,
            'password' => $password,
        ]);

        $email = (new Email())
            ->from('noreply@lovoresume.com')
            ->to($recruteur->getEmail())
            ->subject('Activation de votre compte recruteur')
            ->html($html);

        $this->mailer->send($email);
    }
}
