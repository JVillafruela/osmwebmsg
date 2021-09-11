<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

$msg=file_get_contents(MSG_BODY_FILE);
if ($msg===false) die("Message file not found");

$recipients=file(MSG_RECIPENTS, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if ($recipients===false) die("Message recipients file not found");

$client = new Client();
$client->followRedirects(true);

$ok=login($client,OSM_USER,OSM_PASSWORD);
if (!$ok) die("Wrong password for user " . OSM_USER);


foreach ($recipients as $to) {
    if (empty($to)) continue;
    
    print "Sending to $to \n";
    send($client, trim($to), MSG_SUBJECT, $msg);
}

exit();



//---------------------

function send(Client &$client,string $to,string $subject, string $body) : bool {
    //$to='colargol'; // +++ si n'existe pas ?
    $url="https://www.openstreetmap.org/message/new/$to" ;
    $crawler = $client->request('GET', $url);
    if (!isUserLogged($crawler)) die("Not connected");
    sleep(5);
    
    // css selector found by using dev tools/inspector in Firefox
    $selector='.content-heading > div:nth-child(1) > h1:nth-child(1)'; 
    $n=$crawler->filter($selector)->count();
    if ($n==1) {
       // "The user xxxx does not exist"
        $text=$crawler->filter($selector)->text();
        print "$text \n";
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

    // submit that form
    $crawler = $client->submit($form);
    sleep(5);
    
    return isUserLogged($crawler);
}

function isUserLogged(Crawler &$crawler) {
    $n=$crawler->filter(".username")->count();
    return ($n != 0 );   
}
