<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Routing\Annotation\Route;

final class CustomerContactController extends AbstractController
{
    #[Route('/customer-contact', name: 'app_customer_contact', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        MailerInterface $mailer,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response
    {
        $values = [
            'name' => '',
            'email' => '',
            'subject' => '',
            'message' => '',
        ];

        if ($request->isMethod('POST')) {
            $values['name'] = trim((string) $request->request->get('name', ''));
            $values['email'] = trim((string) $request->request->get('email', ''));
            $values['subject'] = trim((string) $request->request->get('subject', ''));
            $values['message'] = trim((string) $request->request->get('message', ''));
            $csrf = (string) $request->request->get('_csrf_token', '');

            $errors = [];
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('customer_contact', $csrf))) {
                $errors[] = 'Security token expired. Please try again.';
            }
            if ($values['name'] === '') {
                $errors[] = 'Please enter your name.';
            }
            if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if ($values['subject'] === '') {
                $errors[] = 'Please enter a subject.';
            }
            if ($values['message'] === '') {
                $errors[] = 'Please enter a message.';
            }

            if ($errors !== []) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            } else {
                $fromEmail = $_ENV['MAILER_FROM'] ?? 'noreply@geargrid.local';
                $toEmail = $_ENV['CONTACT_TO'] ?? $fromEmail;

                $safeSubject = mb_substr($values['subject'], 0, 140);
                $safeMessage = mb_substr($values['message'], 0, 5000);

                $email = (new Email())
                    ->from(new Address($fromEmail, 'GearGrid Contact'))
                    ->to($toEmail)
                    ->replyTo(new Address($values['email'], $values['name'] !== '' ? $values['name'] : $values['email']))
                    ->subject('Customer Contact: '.$safeSubject)
                    ->text(
                        "New customer contact message\n\n"
                        ."Name: {$values['name']}\n"
                        ."Email: {$values['email']}\n"
                        ."Subject: {$safeSubject}\n\n"
                        ."Message:\n{$safeMessage}\n"
                    )
                    ->html(
                        '<div style="font-family:Arial, sans-serif; line-height:1.6">'
                        .'<h2 style="margin:0 0 12px">New customer contact message</h2>'
                        .'<p style="margin:0 0 6px"><strong>Name:</strong> '.htmlspecialchars($values['name']).'</p>'
                        .'<p style="margin:0 0 6px"><strong>Email:</strong> '.htmlspecialchars($values['email']).'</p>'
                        .'<p style="margin:0 0 6px"><strong>Subject:</strong> '.htmlspecialchars($safeSubject).'</p>'
                        .'<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0" />'
                        .'<p style="white-space:pre-wrap;margin:0">'.nl2br(htmlspecialchars($safeMessage)).'</p>'
                        .'</div>'
                    );

                try {
                    $mailer->send($email);
                } catch (TransportExceptionInterface $e) {
                    $message = $e->getMessage();
                    if (str_contains($message, 'unrecognised IP') || str_contains($message, 'authorized_ips')) {
                        $this->addFlash('error', 'Brevo blocked this server IP. In Brevo go to Settings → Security → Authorized IPs, add your current IP or turn off IP restriction, then try again.');
                    } else {
                        $this->addFlash('error', 'Email could not be sent right now. Please try again.');
                        if ($this->getParameter('kernel.debug')) {
                            $this->addFlash('error', 'Mailer error: '.$message);
                        }
                    }
                    return $this->redirectToRoute('app_customer_contact');
                }

                $this->addFlash('success', 'Message sent! We’ll get back to you soon.');
                return $this->redirectToRoute('app_customer_contact');
            }
        }

        return $this->render('customer/contact.html.twig', [
            'values' => $values,
        ]);
    }
}

