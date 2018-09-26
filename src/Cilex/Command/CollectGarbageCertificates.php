<?php

/*
 * This file is part of the Cilex framework.
 *
 * (c) Mike van Riel <mike.vanriel@naenius.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cilex\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Cilex\Provider\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

/**
 * Example command for testing purposes.
 */
class CollectGarbageCertificates extends Command
{

    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('gc-certs')
            ->setDescription('Collects garbage Kubernetes certificates, basically ones that are older than what you pass as days old as an argument that haven\'t been issued')
            ->addArgument('kubeconfig', InputArgument::REQUIRED);
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {

        // Get arguments
        $kubeconfig = $input->getArgument('kubeconfig');

        // Initialise input/output
        $io = new SymfonyStyle($input, $output);

        $certificateStack = array();
        $goodCertificateStack = array();
        $badCertificateStack = array();
        $secretStack = array();

        $output->writeln("Getting All Secrets:");

        unset($json);
        $command = 'kubectl get secrets --all-namespaces --kubeconfig=' . $kubeconfig . ' -o json';
        //$command = 'kubectl get secrets -n rocol-laravel-qa --kubeconfig=' . $kubeconfig . ' -o json';
        exec($command, $json, $commandStatus);
        if ($commandStatus != 0) {
            throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
        }

        $json = implode("\n", $json);
        $secrets = json_decode($json);

        //print_r($secrets);

        // Hook the secrets up into an array that's indexed by namespace/secret so we can see if it exists more easily
        foreach ($secrets->items as $key => $secret) {
            $secretStack[$secret->metadata->namespace . '/' . $secret->metadata->name] = $secret;
        }

        $output->writeln("Getting All Certificates:");

        // Get all certificates
        unset($json);
        $command = 'kubectl get certificates --all-namespaces --kubeconfig=' . $kubeconfig . ' -o json';
        //$command = 'kubectl get certificates -n rocol-laravel-qa --kubeconfig=' . $kubeconfig . ' -o json';
        exec($command, $json, $commandStatus);
        if ($commandStatus != 0) {
            throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
        }

        $json = implode("\n", $json);
        $certificates = json_decode($json);

        //print_r($certificates);

        foreach ($certificates->items as $key => $certificate) {

            unset($status);
            unset($date);
            unset($timestamp);
            unset($domains);
            unset($method);
            unset($exists);
            unset($expiry);
            unset($certificateData);
            $domains = '';

            $date = date('H:i d/m/Y', strtotime($certificate->metadata->creationTimestamp));
            $timestamp = date('U', strtotime($certificate->metadata->creationTimestamp));

            if(isset($certificate->spec->acme->config[0]->domains) && count($certificate->spec->acme->config[0]->domains) > 0) {
                foreach ($certificate->spec->acme->config[0]->domains as $key => $domain) {
                    $domains .= $domain . ' ';
                }
            }

            if (isset($secretStack[$certificate->metadata->namespace . '/' . $certificate->spec->secretName])) {
                $exists = '✔';
                $certificateData = base64_decode($secretStack[$certificate->metadata->namespace . '/' . $certificate->spec->secretName]->data->{'tls.crt'});
                $expiry = shell_exec("echo '$certificateData' | openssl x509 -enddate -noout");
                $expiryDate = str_replace('notAfter=', '', $expiry);
                $expiry = date('H:i d/m/Y', strtotime($expiryDate));
                if (date('U', strtotime($expiryDate)) < date('U')) {
                    $expiry .= ' EXPIRED!';
                }
            }

            elseif(!isset($secretStack[$certificate->metadata->namespace . '/' . $certificate->spec->secretName])) {
                $exists = '✖';
                $expiry = 'not issued';
            }

            if(isset($certificate->spec->acme->config[0]->http01)) {
                $method = 'http';
            }
            elseif(isset($certificate->spec->acme->config[0]->dns01)) {
                $method = 'dns';
            }

            array_push($certificateStack, 
                array(
                    'name' => $certificate->metadata->name, 
                    'namespace' => $certificate->metadata->namespace, 
                    'domains' => $domains,
                    'exists' => $exists,
                    'date' => $date,
                    'expiry' => $expiry,
                    'timestamp' => $timestamp,
                    'method' => $method,
                    'secret' => $certificate->spec->secretName
                )
            );
        }

        //print_r($secretStack);

        $output->writeln("All Certificates:");

        $table = new Table($output);
        $table
            ->setHeaders(array('Name', 'Namespace', 'Domains', 'Status', 'Order Date', 'Expiry Date', 'Timestamp', 'Method', 'Secret Name'))
            ->setRows($certificateStack);
        $table->render();

        foreach ($certificateStack as $key => $certificate) {
            if ($certificate['exists'] == '✖' && $certificate['timestamp'] <= strtotime('-1 hours')) {
                array_push($badCertificateStack, $certificate);
            }
        }

        $certificateCount = count($certificateStack);
        $badCertificateCount = count($badCertificateStack);

        $output->writeln("Total Certificates: $certificateCount");
        $output->writeln("Total Bad Certificates: $badCertificateCount");

        $output->writeln("These are the certificates that I think are bad and need to be deleted:");

        $table = new Table($output);

        $table
            ->setHeaders(array('Name', 'Namespace', 'Domains', 'Status', 'Date', 'Timestamp', 'Method', 'Secret Name'))
            ->setRows($badCertificateStack);
        $table->render();

        foreach ($badCertificateStack as $key => $certificate) {
            unset($output);
            $command = 'kubectl delete certificate -n ' . $certificate['namespace'] . ' --kubeconfig=' . $kubeconfig . ' ' . $certificate['name'];
            exec($command, $output, $commandStatus);
            if ($commandStatus != 0) {
                throw new \RuntimeException('Kubectl command `' . $command . '` has failed');
            }
            print_r($output);
        }

    }
}
