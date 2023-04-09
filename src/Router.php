<?php

namespace Greew\RenowebToIcs;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Spatie\IcalendarGenerator\Components\Alert;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Enums\Classification;
use Spatie\IcalendarGenerator\Properties\TextProperty;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function filemtime;
use function json_decode;
use function json_encode;

class Router
{
    private Request $request;
    private Client $client;

    private string $cacheDir = __DIR__ . '/../var/cache';
    private string $cacheFile;
    private DateInterval $cacheRetentionPolicy;
    private DateTimeImmutable $cacheCreateDate;

    public static function run(): void
    {
        (new self());
    }

    private function __construct()
    {
        $this->cacheRetentionPolicy = new DateInterval('P1D');
        $this->request = Request::createFromGlobals();
        $this->client = new Client(['base_uri' => 'https://esbjerg.renoweb.dk/Legacy/JService.asmx/']);
        try {
            $response = match ($this->request->getPathInfo()) {
                '/' => $this->routeWebsite(),
                '/addressId' => $this->routeAddressId(),
                '/materials' => $this->routeMaterials(),
                '/ics' => $this->routeIcs(),
                default => new Response('', 404)
            };
        } catch (BadRequestException $e) {
            $response = new Response($e->getMessage(), 400);
        } catch (JsonException $e) {
            $response = new Response("Noget af det modtagne data fra Renoweb blev ikke parset korrekt. Fejlen var: " . $e->getMessage(), 500);
        } catch (GuzzleException $e) {
            $response = new Response("Der skete en fejl under kommunikationen med Renoweb: " . $e->getMessage(), 500);
        } catch (Exception $e) {
            $response = new Response("Der skete en ukendt fejl: " . $e->getMessage(), 500);
        }
        $response->headers->set('Cache-Control', 'public');
        $response->send();
        exit(0);
    }


    /**
     * @throws GuzzleException
     * @throws JsonException
     */
    private function getAddressIdsForAddressText(string $addressText): array
    {
        return $this->renowebRequest('Adresse_SearchByString', [
            'searchterm' => $addressText,
            'addresswithmateriel' => 3
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JsonException
     */
    private function getMaterialsForAddressId(int $addressId): array
    {
        return $this->renowebRequest('GetAffaldsplanMateriel_mitAffald', [
            'adrid' => $addressId,
            'common' => false
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws JsonException
     */
    private function getDatesForMaterials(array $materials): array
    {
        $dates = [];
        foreach ($materials['list'] as $material) {
            $renowebDates = $this->renowebRequest('GetCalender_mitAffald', ['materialid' => $material['id']]);
            $renowebDates = array_map(static fn($str) => preg_replace("/.* (\d{2})-(\d{2})-(\d{4})/", "$3-$2-$1", $str), $renowebDates['list']);
            if (count($renowebDates) === 1 && $renowebDates[0] === 'Ingen planlagte tømninger') {
                continue;
            }
            $dates[$material['materielnavn']] = $renowebDates;
        }
        return $dates;
    }


    /**
     * @throws GuzzleException
     * @throws JsonException
     * @throws Exception
     */
    private function createIcsData(): void
    {
        $addressId = $this->request->get('addressId');
        $materials = $this->getMaterialsForAddressId($addressId);
        $materials = $this->getDatesForMaterials($materials);

        $cal = new Calendar('Affaldsplan');
        $cal
            ->withoutTimezone();
        foreach ($materials as $type => $dates) {
            foreach ($dates as $date) {
                $tz = new DateTimeZone('UTC');
                $date = new DateTimeImmutable($date);
                $createdDate = new DateTimeImmutable("{$date->format('Y')}-01-01", $tz);
                $alertDate = $date->sub($this->cacheRetentionPolicy)->setTime(20, 0);
                $uid = sha1($type . $date->format('Y-m-d')) . '@affald.skytte.it';
                $cal->event(Event::create($type)
                    ->createdAt($createdDate)
                    ->uniqueIdentifier($uid)
                    ->startsAt($date)
                    ->endsAt($date)
                    ->appendProperty(new TextProperty('FBTYPE', 'FREE'))
                    ->appendProperty(new TextProperty('TRANSP', 'TRANSPARENT'))
                    ->alert(Alert::date($alertDate, "Affaldsafhentning: $type"))
                    ->fullDay()
                    ->classification(Classification::public())
                );
            }
        }
        file_put_contents($this->cacheFile, $cal->get());
        $this->cacheCreateDate = DateTimeImmutable::createFromFormat('U', filemtime($this->cacheFile));
    }

    /**
     * Create a request to the Renoweb API
     *
     * @param string $url
     * @param array $data
     * @return array
     * @throws JsonException
     * @throws GuzzleException
     */
    private function renowebRequest(string $url, array $data): array
    {
        $response = $this->client->request(
            'POST',
            $url,
            [
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => json_encode($data, JSON_THROW_ON_ERROR)
            ]
        );

        $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        return json_decode($data['d'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The default route for getting the website
     *
     * @return Response
     */
    private function routeWebsite(): Response
    {
        $response = new Response();
        $response->setContent(file_get_contents(dirname(__DIR__) . '/resources/index.html'));
        $response->headers->set('Content-Type', 'text/html');
        return $response;
    }

    /**
     * The route for getting a list of the address ids
     *
     * @return JsonResponse
     * @throws JsonException
     * @throws GuzzleException
     */
    private function routeAddressId(): JsonResponse
    {
        if (!$this->request->query->has('address')) {
            throw new BadRequestException('Missing address search text');
        }
        $address = $this->request->get('address');
        $data = $this->getAddressIdsForAddressText($address);
        return (new JsonResponse())->setContent($data['list']);
    }

    /**
     * The route for getting a list of the materials
     *
     * @return JsonResponse
     * @throws JsonException
     * @throws GuzzleException
     */
    private function routeMaterials(): JsonResponse
    {
        if (!$this->request->query->has('addressId')) {
            throw new BadRequestException('Missing addressId');
        }
        $addressId = $this->request->get('addressId');
        $data = $this->getMaterialsForAddressId($addressId);
        return (new JsonResponse())->setContent($data['list']);
    }

    /**
     * The route for getting the ICS file
     *
     * @return Response
     * @throws JsonException
     * @throws Exception
     * @throws GuzzleException
     */
    private function routeIcs(): Response
    {
        if (!$this->request->query->has('addressId')) {
            throw new BadRequestException('Missing addressId');
        }

        $format = $this->request->query->get('format', 'ics');
        $query = $this->request->query->getIterator();
        $query->offsetUnset('format');
        $query->ksort();

        $cacheKey = sha1(serialize($query));
        $this->cacheFile = $this->getCacheFilePath($cacheKey);
        $hasCacheFile = is_file($this->cacheFile);
        // If cache file is missing or cache has been invalidated, create new cache file
        if (!$hasCacheFile || !$this->isCacheValid()) {
            $this->createIcsData();
        }

        // Create the appropriate response
        $response = match ($format) {
            'ics' => $this->createIcsResponse($this->cacheFile),
            'text' => $this->createTextResponse($this->cacheFile),
            default => throw new BadRequestException('Invalid format')
        };
        $response->setLastModified($this->cacheCreateDate);
        return $response;
    }

    /**
     * Check if the cache file is valid
     *
     * @return bool
     */
    private function isCacheValid(): bool
    {
        $this->cacheCreateDate = DateTimeImmutable::createFromFormat('U', filemtime($this->cacheFile));
        $cacheInvalidateDate = (new DateTimeImmutable())->sub(new DateInterval('P1D'));
        return $this->cacheCreateDate >= $cacheInvalidateDate;
    }

    /**
     * Get the path to the cache file
     *
     * @param string $cacheKey
     * @return string
     */
    private function getCacheFilePath(string $cacheKey): string
    {
        return $this->cacheDir . '/' . $cacheKey;
    }

    /**
     * Create a binary response with the contents of the cache file
     *
     * @param string $cacheFile
     * @return BinaryFileResponse
     */
    private function createIcsResponse(string $cacheFile): BinaryFileResponse
    {
        $response = new BinaryFileResponse($cacheFile);
        $response->headers->set('Content-Type', 'text/calendar');
        $response->headers->set('Content-Disposition', 'attachment; filename="affaldsafhentning.ics"');
        return $response;
    }

    /**
     * Create a text response with the contents of the cache file
     *
     * @param string $cacheFile
     * @return Response
     */
    private function createTextResponse(string $cacheFile): Response
    {
        $response = new Response(file_get_contents($cacheFile));
        $response->headers->set('Content-Type', 'text/plain');
        return $response;
    }
}
