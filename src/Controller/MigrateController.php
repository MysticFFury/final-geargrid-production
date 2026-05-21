<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class MigrateController extends AbstractController
{
    #[Route('/run-migrations-secret-123', name: 'run_migrations')]
    public function migrate(KernelInterface $kernel): Response
    {
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
        ]);

        $output = new BufferedOutput();
        try {
            $application->run($input, $output);
            $content = $output->fetch();
        } catch (\Exception $e) {
            $content = $e->getMessage();
        }

        return new Response("<html><body><h1>Migration Completed</h1><pre>".$content."</pre></body></html>");
    }
}
