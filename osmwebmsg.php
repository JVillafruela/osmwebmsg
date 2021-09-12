<?php
require __DIR__ . '/vendor/autoload.php';

use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\Options;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class App extends PSR3CLI {
    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options) {
        $options->setHelp('Utility so send messages to OpenStreetMap users');

        $options->registerOption('user', 'OSM user name', 'u','username');
        $options->registerOption('password', 'OSM user password', 'p', 'password');
        $options->registerOption('subject', 'Message subject', 's', 'subject');
        $options->registerOption('message', 'Message', 'm', 'filename');
        $options->registerOption('recipients', 'Recipients list', 'r', 'filename');        
    }

    /**
     * main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param Options $options
     * @return void
     */
    protected function main(Options $options) {
        // these options are mandatory
        foreach (array('user','password','subject','message','recipients') as $option) {       
            if (!$options->getOpt($option)) {
                print $options->help();
                exit(1);
            } 
        }

        $user=$options->getOpt('user');
        $password=$options->getOpt('password');
        $subject=$options->getOpt('subject');
        $fmsg=$options->getOpt('message');
        $frecipients=$options->getOpt('recipients');

        if (!is_readable($fmsg)) {
            $this->error("File $fmsg not found");
            exit(1);
        }

        if (!is_readable($frecipients)) {
            $this->error("File $frecipients not found");
            exit(1);
        }
    
        $msg=file_get_contents($fmsg);
        if ($msg===false) die("Message file not found");

        $recipients=file($frecipients, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if ($recipients===false) die("Message recipients file not found");

        $client = new Client();
        $client->followRedirects(true);

        $ok=$this->login($client,$user,$password);
        if (!$ok) die("Wrong password for user $user");

        $n=0;
        foreach ($recipients as $to) {
            if (empty($to)) continue;

            $this->info("Sending to $to \n");
            if ($this->send($client, trim($to), $subject, $msg)) $n++;
        }
        
        $this->success("$n messages sent");
    }

    function send(Client &$client,string $to,string $subject, string $body) : bool {
        $url="https://www.openstreetmap.org/message/new/$to" ;
        $crawler = $client->request('GET', $url);
        if (!$this->isUserLogged($crawler)) die("Not connected");
        sleep(5);

        // css selector found by using dev tools/inspector in Firefox
        $selector='.content-heading > div:nth-child(1) > h1:nth-child(1)'; 
        $n=$crawler->filter($selector)->count();
        if ($n==1) {
           // "The user xxxx does not exist"
            $text=$crawler->filter($selector)->text();
            $this->error($text);
            return false;
        }

        $form = $crawler->selectButton('Send')->form();
        $form['message[title]'] = $subject;
        $form['message[body]'] = $body;
        $crawler = $client->submit($form);
        sleep(15);
        return true;
    }

    function login(Client &$client, string $user, string $password) : bool {
        $crawler = $client->request('GET', 'https://www.openstreetmap.org/login');

        $form = $crawler->selectButton('Login')->form();
        $form['username'] = $user;
        $form['password'] = $password;

        $crawler = $client->submit($form);
        sleep(5);

        return $this->isUserLogged($crawler);
    }

    function isUserLogged(Crawler &$crawler) {
        $n=$crawler->filter(".username")->count();
        return ($n != 0 );   
    }

}


$cli = new App();
$cli->run();
