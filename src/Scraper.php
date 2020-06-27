<?php

namespace Avtonet;

use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;

class Scraper {

    public function run()
    {
    $client = new Client(HttpClient::create());
    $client->setServerParameter('HTTP_USER_AGENT', 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:73.0) Gecko/20100101 Firefox/73.0');
    $client->setMaxRedirects(5);

    $cars = [];

    $root = "https://www.avto.net";
    $filters = '/Ads/results.asp?znamka=&model=&modelID=&tip=&znamka2=&model2=&tip2=&znamka3=&model3=&tip3=&cenaMin=0&cenaMax=999999&letnikMin=0&letnikMax=2090&bencin=0&starost2=999&oblika=0&ccmMin=0&ccmMax=99999&mocMin=&mocMax=&kmMin=0&kmMax=9999999&kwMin=0&kwMax=999999&motortakt=0&motorvalji=0&lokacija=0&sirina=0&dolzina=&dolzinaMIN=0&dolzinaMAX=100&nosilnostMIN=0&nosilnostMAX=999999&lezisc=&presek=0&premer=0&col=0&vijakov=0&EToznaka=0&vozilo=&airbag=&barva=&barvaint=&EQ1=1000000000&EQ2=1000000000&EQ3=1000000000&EQ4=100000000&EQ5=1000000000&EQ6=1000001000&EQ7=1110100122&EQ8=1010000001&EQ9=100000000&KAT=1010000000&PIA=&PIAzero=&PSLO=&akcija=0&paketgarancije=&broker=469&prikazkategorije=0&kategorija=0&zaloga=10&arhiv=0&presort=&tipsort=&';


    $pageCount = 5;

    // first check how many pages do we need to crawl
    $crawler = $client->request('GET', $root . $filters . "stran=1");
    $crawler->filter('.ResultsMenuBoxnazivTXT')->each(function ($node) use (&$pageCount) {
        preg_match_all('/\d+/', trim($node->text()), $matches);
        $totalCount = $matches[0][0] ?? null;
        if($totalCount) {
            $pageCount = (int)ceil($totalCount / 50);
        }
    });

    for($page = 1; $page <= $pageCount; $page++) {
        $crawler = $client->request('GET', $root . $filters . "stran=" . $page);

        // get all the listed ads
        $crawler->filter('.ResultsAd .Adlink')->each(function ($node) use (&$cars, $root, $client) {
            $href = $node->extract(array('href'));
            
            $link = $href[0];
    
            // crawl single car page
            if(strpos($link, 'details.asp') !== false) {
                $newLink = str_replace('../Ads', $root . "/Ads", $link);
                
                // todo get id from this link, store in DB as unique identifier
                print($newLink);
                if(!in_array($newLink, $cars)) {
                    $cars[] = $newLink;

                    $crawler = $client->request('GET', $newLink);
        
                    $car = [];

                    // car name
                    $crawler->filter('.OglasDataTitle.Og1asDataZero.Titel h1')->each(function ($node) use (&$car) {
                        $name = trim($node->text());
                        print($name . '<br>');
                        $car['name'] = $name;
                    });
                    
                    // car images
                    $crawler->filter('.OglasThumb')->each(function ($node) use (&$car) {
                        $src = $node->extract(array('src'));
                        $img = str_replace('_small', '', $src[0]);
                        print( $img . '<br>');
                        $car['images'][] = $img;
                    });
                    
                    // car price
                    $crawler->filter('.OglasDataCenaTOP')->each(function ($node) use (&$car) {
                        $car['price'] = trim(str_replace('€', '', $node->text()));
                    });
        
                    // all the car details
                    $crawler->filter('.OglasData')->each(function ($node) use (&$car) {

                        $dict = [
                            // Osnovni podatki:
                            'Starost' => 'age',
                            'Prva registracija' => 'first_reg',
                            'Leto proizvodnje' => 'year',
                            'Prevoženi km' => 'km',
                            'Tehnični pregled' => 'technical',
                            'Motor'     => 'engine',
                            'Menjalnik' => 'gearbox',
                            'Oblika karoserije' => 'type',
                            'Število vrat' => 'doors',
                            'Barva'     => 'color',
                            'Notranjost' => 'interior',
                            'Interna številka' => 'internal_num',
                            'VIN / številka šasije' => 'vin',
                            'Zgodovina vozila' => 'carfax', // if carfax, get link
                            'Kraj ogleda' => 'location',

                            // Poraba goriva in emisije:
                            'Kombinirana vožnja' => 'combined_drive',
                            'Izvenmestna vožnja' => 'out_drive',
                            'Mestna vožnja'     => 'city_drive',
                            'Emisijski razred' => 'emission_class',
                            'Emisija CO2'       => 'emission_km',

                            // Oprema in ostali podatki o ponudbi
                            // Vse nastavi na boolean, če obstaja!

                            'Podvozje' => 'explode it',
                            'if(str_includes("platišča", $val) !== false)' => 'wheels', // string
                            'ABS zavorni sistem' => 'abs',
                            'BAS pomoč pri zaviranju' => 'bas',
                            'ESP elektronski program stabilnosti' => 'esp',
                            'ASR regulacija zdrsa pogonskih koles' => 'asr',

                            'Varnost' => 'explode it 2',
                            'if(str_includes("Airbag", $val) !== false)' => 'airbag',  // string
                            'senzor za dež' => 'rain_sensor',
                            'prednje (dnevne) LED luči' => 'front_led',
                            'zadnje LED luči' => 'rear_led',
                            'meglenke' => 'foglights',
                            'tretja zavorna luč' => 'third_stoplight',
                            'alarmna naprava' => 'alarm',
                            'kodno varovan vžig motorja' => 'code_ignition',
                            'nadzor zračnega tlaka v pnevmatikah' => 'tire_pressure',
                            'if(str_includes("rezervno kolo", $val) !== false)' => 'spare_wheel',
                        ];

                        $text = $node->text();

                        $split = explode(':', $text, 2); 
                        $key = trim($split[0]);
                        
                        $value = trim($split[1]);

                        if(key_exists($key, $dict)) {
                            $car[$dict[$key]] = $value;
                        }
                        
                        print('<b>' . $key . '</b> : ' . $value . '<br>');
                        
                    });
                    
                }

                print('----- <br><br>');
    
            }
        });

    }
    return $cars;
    }
}
