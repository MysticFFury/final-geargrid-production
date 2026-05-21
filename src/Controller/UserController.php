<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Log;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[Route('/user')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            } else {
                $this->addFlash('error', 'Password is required to create a new user.');

                return $this->render('user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Staff/admin accounts do not need email verification (same as web UserChecker).
            $roles = $user->getRoles();
            if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_STAFF', $roles, true)) {
                $user->setIsVerified(true);
                $user->setVerificationToken(null);
            }

            $entityManager->persist($user);
            $entityManager->flush();

            // Log admin user creation
            $currentUser = $this->getUser();
            $log = new Log();
            $log->setAction('CREATE')
                ->setMessage("Admin '{$currentUser->getUserIdentifier()}' created user '{$user->getUserIdentifier()}'")
                ->setStatus('active')
                ->setUserName($currentUser->getUserIdentifier())
                ->setUserRole(implode(', ', $currentUser->getRoles()))
                ->setEntity('User')
                ->setCreatedAt(new \DateTime());
            $entityManager->persist($log);
            $entityManager->flush();

            $this->addFlash('success', 'User created successfully.');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $form = $this->createForm(UserType::class, $user, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if ($plainPassword) {
                $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                $user->setPassword($hashedPassword);
            }

            $entityManager->flush();
            $this->addFlash('success', 'User updated successfully.');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle', name: 'app_user_toggle', methods: ['POST'])]
    public function toggle(User $user, EntityManagerInterface $entityManager, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('toggle'.$user->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_user_index');
        }

        $user->setIsActive(!$user->isActive());
        $entityManager->flush();

        $this->addFlash('success', $user->isActive() ? 'User activated.' : 'User deactivated.');

        return $this->redirectToRoute('app_user_index');
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $currentUser = $this->getUser();
            if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
                $this->addFlash('error', 'You cannot delete your own account.');

                return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
            }

            $deletedUserEmail = $user->getUserIdentifier();

            $userRepository->detachUserReferences($user);
            $entityManager->remove($user);
            $entityManager->flush();

            // Log admin user deletion
            $log = new Log();
            $log->setAction('DELETE')
                ->setMessage("Admin '{$currentUser->getUserIdentifier()}' deleted user '{$deletedUserEmail}'")
                ->setStatus('active')
                ->setUserName($currentUser->getUserIdentifier())
                ->setUserRole(implode(', ', $currentUser->getRoles()))
                ->setEntity('User')
                ->setCreatedAt(new \DateTime());
            $entityManager->persist($log);
            $entityManager->flush();

            $this->addFlash('success', 'User deleted successfully.');
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
