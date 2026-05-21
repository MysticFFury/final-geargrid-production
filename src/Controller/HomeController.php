<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): RedirectResponse
    {
        if ($this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_STAFF')) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->redirectToRoute('app_customer_landing');
    }
}
