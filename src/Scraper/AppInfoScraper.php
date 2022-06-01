<?php

/** @noinspection MultiAssignmentUsageInspection */
declare(strict_types=1);

/**
 * @author   Ne-Lexa
 * @license  MIT
 *
 * @see      https://github.com/Ne-Lexa/google-play-scraper
 */

namespace Nelexa\GPlay\Scraper;

use Nelexa\GPlay\Exception\GooglePlayException;
use Nelexa\GPlay\GPlayApps;
use Nelexa\GPlay\Model\AppId;
use Nelexa\GPlay\Model\AppInfo;
use Nelexa\GPlay\Model\Category;
use Nelexa\GPlay\Model\Developer;
use Nelexa\GPlay\Model\GoogleImage;
use Nelexa\GPlay\Model\HistogramRating;
use Nelexa\GPlay\Model\Review;
use Nelexa\GPlay\Model\Video;
use Nelexa\GPlay\Scraper\Extractor\ReviewsExtractor;
use Nelexa\GPlay\Util\DateStringFormatter;
use Nelexa\GPlay\Util\LocaleHelper;
use Nelexa\GPlay\Util\ScraperUtil;
use Nelexa\HttpClient\ResponseHandlerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Psr7\parse_query;

/**
 * @internal
 */
class AppInfoScraper implements ResponseHandlerInterface
{
    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @throws GooglePlayException
     *
     * @return AppInfo
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response): AppInfo
    {
        $query = parse_query($request->getUri()->getQuery());

        $id = $query[GPlayApps::REQ_PARAM_ID];
        $locale = $query[GPlayApps::REQ_PARAM_LOCALE] ?? GPlayApps::DEFAULT_LOCALE;
        $country = $query[GPlayApps::REQ_PARAM_COUNTRY] ?? GPlayApps::DEFAULT_COUNTRY;

        /** @var array|null $scriptDataRating */
        [
            $scriptDataInfo,
            $scriptDataRating,
            $scriptDataPrice,
            $scriptDataVersion,
            $scriptDataReviews,
        ] = $this->getScriptData($request, $response);
//dd(
////    $scriptDataInfo,
////    $scriptDataRating,
//    $scriptDataPrice,
//    $scriptDataVersion,
//    $scriptDataReviews,
//);
        $name = $scriptDataInfo[1][2][0][0];
        $description = $this->extractDescription($scriptDataInfo);
        $translatedFromLocale = $this->extractTranslatedFromLocale($scriptDataInfo, $locale);
        $developer = $this->extractDeveloper($scriptDataInfo);

        if (isset($scriptDataInfo[1][2][79][0][0])) {
            $category = $this->extractCategory($scriptDataInfo[1][2][79][0][0]);
        } elseif (!empty($data[1][2][118][0][0][0])) {
            $category = $this->extractCategory($scriptDataInfo[1][2][118][0][0][0]);
        } else {
            $category = null;
        }

        $summary = $this->extractSummary($scriptDataInfo);
        $installs = $scriptDataInfo[1][2][13][2] ?? 0;
        $score = (float) ($scriptDataRating[0][0] ?? 0);
        $numberVoters = (int) ($scriptDataRating[2][1] ?? 0);
        $numberReviews = (int) ($scriptDataRating[3][1] ?? 0);
        $histogramRating = $this->extractHistogramRating($scriptDataRating);
        $price = $scriptDataPrice !== null ? $this->extractPrice($scriptDataPrice) : 0.0;
        $currency = $scriptDataPrice[1][2][57][0][0][0][0][1][0][1] ?? 'USD';
        $priceText = $scriptDataPrice[1][2][57][0][0][0][0][1][0][2] ?? null;
        $offersIAPCost = $scriptDataInfo[0][12][12][0] ?? null;
        $containsAds = (bool) ($scriptDataInfo[1][2][48][0] ?? false);

        $appVersion = $scriptDataVersion[0][0][0] ?? '';
        $size = null;
        $androidVersion = $scriptDataVersion[1][0][0][1] ?? '';
        $minAndroidVersion = $scriptDataVersion[1][1][0][0][1] ?? preg_replace('~.*?(\d+(\.\d+)*).*~', '$1', $androidVersion);;

        $editorsChoice = !empty($scriptDataInfo[0][12][15][1][1]);
        $privacyPoliceUrl = $scriptDataInfo[1][2][99][0][5][2] ?? '';
        $categoryFamily = $this->extractCategory($scriptDataInfo[0][12][13][1] ?? []);
        $icon = $this->extractIcon($scriptDataInfo);
        $cover = $this->extractCover($scriptDataInfo);
        $screenshots = $this->extractScreenshots($scriptDataInfo);

        $video = $this->extractVideo($scriptDataInfo);
        $contentRating = $scriptDataInfo[1][2][111][1] ?? '';
        $released = $this->extractReleaseDate($scriptDataInfo, $locale);
        $updated = $this->extractUpdatedDate($scriptDataInfo);
        $recentChanges = $this->extractRecentChanges($scriptDataInfo);

        $reviews = $this->extractReviews(new AppId($id, $locale, $country), $scriptDataReviews);

        return AppInfo::newBuilder()
            ->setId($id)
            ->setLocale($locale)
            ->setCountry($country)
            ->setName($name)
            ->setDescription($description)
            ->setTranslatedFromLocale($translatedFromLocale)
            ->setSummary($summary)
            ->setIcon($icon)
            ->setCover($cover)
            ->setScreenshots($screenshots)
            ->setDeveloper($developer)
            ->setCategory($category)
            ->setCategoryFamily($categoryFamily)
            ->setVideo($video)
            ->setRecentChanges($recentChanges)
            ->setEditorsChoice($editorsChoice)
            ->setPrivacyPoliceUrl($privacyPoliceUrl)
            ->setInstalls($installs)
            ->setScore($score)
            ->setNumberVoters($numberVoters)
            ->setHistogramRating($histogramRating)
            ->setPrice($price)
            ->setCurrency($currency)
            ->setPriceText($priceText)
            ->setOffersIAPCost($offersIAPCost)
            ->setContainsAds($containsAds)
            ->setSize($size)
            ->setAppVersion($appVersion)
            ->setAndroidVersion($androidVersion)
            ->setMinAndroidVersion($minAndroidVersion)
            ->setContentRating($contentRating)
            ->setReleased($released)
            ->setUpdated($updated)
            ->setNumberReviews($numberReviews)
            ->setReviews($reviews)
            ->buildDetailInfo()
        ;
    }

    /**
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     *
     * @throws GooglePlayException
     *
     * @return array
     */
    private function getScriptData(RequestInterface $request, ResponseInterface $response): array
    {
        $scriptData = ScraperUtil::extractScriptData($response->getBody()->getContents());
        $scriptDataInfo = null;
        $scriptDataRating = null;
        $scriptDataPrice = null;
        $scriptDataVersion = null;
        $scriptDataReviews = [];
//dd($scriptData);
        foreach ($scriptData as $key => $scriptValue) {
//            dd($key, $scriptValue);die();

            if (isset($scriptValue[1][2][0][0]) && \is_string($scriptValue[1][2][0][0]) && isset($scriptValue[1][2][77][0])) { // ds:4
                $scriptDataInfo = $scriptValue;
                $scriptDataVersion = $scriptValue[1][2][140];
                $scriptDataRating = $scriptValue[1][2][51];
                $scriptDataPrice = $scriptValue[1][2][57];
            } elseif (isset($scriptValue[0][0][0])
                && \is_string($scriptValue[0][0][0])
                && strpos($scriptValue[0][0][0], 'gp:') === 0) { // ds:8
                $scriptDataReviews = $scriptValue;
            }
        }

        if (
            $scriptDataInfo === null ||
            $scriptDataVersion === null
        ) {
            throw (new GooglePlayException('Unable to get data for this application.'))->setUrl(
                $request->getUri()->__toString()
            );
        }

        return [$scriptDataInfo, $scriptDataRating, $scriptDataPrice, $scriptDataVersion, $scriptDataReviews];
    }

    /**
     * @param        $scriptDataInfo
     * @param string $locale
     *
     * @return string|null
     */
    private function extractTranslatedFromLocale(array $scriptDataInfo, string $locale): ?string
    {
        return isset($scriptDataInfo[0][19][1]) ?
            LocaleHelper::findPreferredLanguage(
                $locale,
                $scriptDataInfo[0][19][1]
            ) :
            null;
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return string
     */
    private function extractDescription(array $scriptDataInfo): string
    {
        if (isset($scriptDataInfo[1][2][72][0][1])) {
            return ScraperUtil::html2text($scriptDataInfo[1][2][72][0][1]);
        }

        return ScraperUtil::html2text($scriptDataInfo[1][2][144][1][1] ?? '');
    }

    /**
     * @param $scriptDataInfo
     *
     * @return string|null
     */
    private function extractSummary(array $scriptDataInfo): ?string
    {
        return empty($scriptDataInfo[1][2][73][0][1]) ?
            null :
            ScraperUtil::html2text($scriptDataInfo[1][2][73][0][1]);
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return Developer
     */
    private function extractDeveloper(array $scriptDataInfo): Developer
    {
        $developerPage = GPlayApps::GOOGLE_PLAY_URL . $scriptDataInfo[1][2][68][1][4][2];
        $developerId = parse_query(parse_url($developerPage, \PHP_URL_QUERY))[GPlayApps::REQ_PARAM_ID];
        $developerName = $scriptDataInfo[1][2][68][0];
        $developerEmail = $scriptDataInfo[1][2][69][1][0] ?? null;
        $developerWebsite = $scriptDataInfo[1][2][69][0][5][2] ?? null;
        $developerAddress = $scriptDataInfo[1][2][69][2][0] ?? null;
//        $developerInternalID = (int)$scriptDataInfo[0][12][5][0][0];

        return new Developer(
            Developer::newBuilder()
                ->setId($developerId)
                ->setUrl($developerPage)
                ->setName($developerName)
                ->setEmail($developerEmail)
                ->setAddress($developerAddress)
                ->setWebsite($developerWebsite)
        );
    }

    /**
     * @param array $data
     *
     * @return Category
     */
    private function extractCategory(array $data): ?Category
    {
        if (isset($data[0], $data[2])) {
            $genreId = (string) $data[2];
            $genreName = (string) $data[0];

            return new Category($genreId, $genreName);
        }

        return null;
    }

    /**
     * @param array|null $scriptDataRating
     *
     * @return HistogramRating
     */
    private function extractHistogramRating(?array $scriptDataRating): HistogramRating
    {
        return new HistogramRating(
            $scriptDataRating[1][5][1] ?? 0,
            $scriptDataRating[1][4][1] ?? 0,
            $scriptDataRating[1][3][1] ?? 0,
            $scriptDataRating[1][2][1] ?? 0,
            $scriptDataRating[1][1][1] ?? 0
        );
    }

    /**
     * @param array $scriptDataPrice
     *
     * @return float
     */
    protected function extractPrice(array $scriptDataPrice): ?float
    {
        return isset($scriptDataPrice[1][2][57][0][0][0][0][1][0][0]) ?
            (float) ($scriptDataPrice[1][2][57][0][0][0][0][1][0][0] / 1000000) :
            0.0;
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return GoogleImage|null
     */
    protected function extractIcon(array $scriptDataInfo): ?GoogleImage
    {
        return empty($scriptDataInfo[1][2][95][0][3][2]) ?
            null :
            new GoogleImage($scriptDataInfo[1][2][95][0][3][2]);
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return GoogleImage|null
     */
    protected function extractCover(array $scriptDataInfo): ?GoogleImage
    {
        return empty($scriptDataInfo[1][2][96][0][3][2]) ?
            null :
            new GoogleImage($scriptDataInfo[1][2][96][0][3][2]);
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return GoogleImage[]
     */
    private function extractScreenshots(array $scriptDataInfo): array
    {
        return !empty($scriptDataInfo[1][2][78][0]) ? array_map(
            static function (array $v) {
                return new GoogleImage($v[3][2]);
            },
            $scriptDataInfo[1][2][78][0]
        ) : [];
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return Video|null
     */
    private function extractVideo(array $scriptDataInfo): ?Video
    {
        if (
            isset($scriptDataInfo[1][2][100][0][0][3][2]) &&
            $scriptDataInfo[1][2][100][0][0][3][2] !== null &&
            $scriptDataInfo[1][2][100][0][1][3][2] !== null
        ) {
            $videoThumb = (string) $scriptDataInfo[1][2][100][0][1][3][2];
            $videoUrl = (string) $scriptDataInfo[1][2][100][0][0][3][2];

            return new Video($videoThumb, $videoUrl);
        }

        return null;
    }

    /**
     * @param array  $scriptDataInfo
     * @param string $locale
     *
     * @return \DateTimeInterface|null
     */
    private function extractReleaseDate(array $scriptDataInfo, string $locale): ?\DateTimeInterface
    {
        if (isset($scriptDataInfo[1][2][10][0])) {
            return DateStringFormatter::formatted($locale, $scriptDataInfo[1][2][10][0]);
        }

        return null;
    }

    /**
     * @param array $scriptDataInfo
     *
     * @return \DateTimeInterface|null
     */
    private function extractUpdatedDate(array $scriptDataInfo): ?\DateTimeInterface
    {
        if (isset($scriptDataInfo[1][2][145][0][0])) {
            return DateStringFormatter::unixTimeToDateTime($scriptDataInfo[1][2][145][0][0]);
        }

        return null;
    }

    /**
     * @param $scriptDataInfo
     *
     * @return string|null
     */
    protected function extractRecentChanges($scriptDataInfo): ?string
    {
        return empty($scriptDataInfo[1][2][144][1][1]) ?
            null :
            ScraperUtil::html2text($scriptDataInfo[1][2][144][1][1]);
    }

    /**
     * @param AppId $appId
     * @param array $scriptDataReviews
     * @param int   $limit
     *
     * @return Review[]
     */
    private function extractReviews(AppId $appId, array $scriptDataReviews, int $limit = 4): array
    {
        if (empty($scriptDataReviews[0])) {
            return [];
        }

        return ReviewsExtractor::extractReviews(
            $appId,
            \array_slice($scriptDataReviews[0], 0, $limit)
        );
    }
}
