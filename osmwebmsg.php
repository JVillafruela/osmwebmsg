<?php
require __DIR__ . '/vendor/autoload.php';

use splitbrain\phpcli\PSR3CLI;
use splitbrain\phpcli\Options;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class App extends PSR3CLI {
    
    const ERR_NO_ERROR=0;
    const ERR_USER_NOT_FOUND=1;
    const ERR_TOO_MANY_MSG=2;
    
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

        $this->debug("Creation client 1");
        $client = new Client();
        $this->debug("Creation client 2");
        $client->followRedirects(true);

        $ok=$this->login($client,$user,$password);
        if (!$ok) die("Wrong password for user $user");

        $n=0;
        foreach ($recipients as $to) {
            if (empty($to)) continue;

            $this->info("Sending to $to \n");
            $err=$this->send($client, trim($to), $subject, $msg);
            switch ($err) {
                case self::ERR_NO_ERROR:
                    $n++;
                    break;
                case self::ERR_TOO_MANY_MSG:
                    $this->error("Aborting. $n messages sent");
                    exit(2);
                    break;
            }                   
        }
        
        $this->success("$n messages sent");
    }

    function send(Client &$client,string $to,string $subject, string $body) : int {
        $this->debug("Entering send() to=$to ");
        $url="https://www.openstreetmap.org/message/new/$to" ;
        $crawler = $client->request('GET', $url);
        if (!$this->isUserLogged($crawler)) {
            $this->error("Not connected");
            die();
        }
        sleep(5);

        // css selector found by using dev tools/inspector in Firefox (copy /selector css)
        //$selector='.content-heading > div:nth-child(1) > h1:nth-child(1)'; 
        $selector='#new_message';
        $n=$crawler->filter($selector)->count();
        $this->debug("Checking for send form n=$n");
        if ($n==0) {
           // "The user xxxx does not exist"
           //$text=$crawler->filter($selector)->text();
            $this->error("The user $to does not exist");
            return self::ERR_USER_NOT_FOUND;
        }
        
        $this->debug("Filling and sending form");
        $form = $crawler->selectButton('Send')->form();
        $form['message[title]'] = $subject;
        $form['message[body]'] = $body;
        $crawler = $client->submit($form);
        
         // You have sent a lot of messages recently. Please wait a while before trying to send any more.
        $selector='html body.messages.messages-create div#content div.flash.error.row.align-items-center div.col';
        $n = $crawler->filter($selector)->count();
        $this->debug("After submit error=$n");
        if ($n==1) {
            $text=$crawler->filter($selector)->text();
            $this->error($text);
            dumpResponse($client, "error.html");
            return self::ERR_TOO_MANY_MSG;
        }        
        
        sleep(15);
        return self::ERR_NO_ERROR;
    }

    function login(Client &$client, string $user, string $password) : bool {
        $this->debug("Login $user");
        $crawler = $client->request('GET', 'https://www.openstreetmap.org/login');
        $this->debug("Login : crawler OK");

        $form = $crawler->selectButton('Log in')->form();
        $form['username'] = $user;
        $form['password'] = $password;

        $this->debug("Login : before submit");
        $crawler = $client->submit($form);
        sleep(5);

        return $this->isUserLogged($crawler);
    }

    function isUserLogged(Crawler &$crawler) {
        $n=$crawler->filter(".username")->count();
        return ($n != 0 );   
    }

}

function dumpResponse(Client &$client,$filename) {
    $response = $client->getResponse();
    file_put_contents($filename, $response);
}

$cli = new App();
$cli->run();
