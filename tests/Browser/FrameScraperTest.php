<?php

namespace Tests\Browser;
use Illuminate\Support\Facades\File;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Facebook\WebDriver\Exception\NoSuchElementException;

class FrameScraperTest extends DuskTestCase
{
    protected function setUserAgent(Browser $browser): void
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Safari/537.36',
        ];
        $browser->driver->executeScript("navigator.__defineGetter__('userAgent', function(){ return '" . $userAgents[array_rand($userAgents)] . "'; });");
    }

    private function getPageUrl($categoryUrl, $page): array|string|null
    {
        if (preg_match('/all-\w+-\d+-(\d+)-(\d+)-(\d+)-(\d+)-(\d+)/', $categoryUrl)) {
            $newUrl = preg_replace("/-(\d+)-(\d+)-(\d+)-(\d+)-(\d+)$/", "-$1-{$page}-$3-$4-$5", $categoryUrl);
        } elseif (preg_match('/0-New%20Stock-(\d+)-25-Arrival/', $categoryUrl)) {
            $newUrl = preg_replace("/-(\d+)-25-/", "-{$page}-25-", $categoryUrl);
        } elseif (preg_match('/Clearance-Sale-\d+-(\d+)-25/', $categoryUrl)) {
            $newUrl = preg_replace("/-(\d+)-25$/", "-{$page}-25", $categoryUrl);
        } else {
            $newUrl = preg_replace('/-(\d+)-25-/', "-{$page}-25-", $categoryUrl);
        }
        return $newUrl;
    }

    public function testScrapeFrames()
    {
        ob_implicit_flush(true);
        while (ob_get_level()) ob_end_flush();

        $startTime = microtime(true);
        echo "🚀 Начинаем парсинг...\n";

        $this->browse(function (Browser $browser) {
            echo "🔑 Авторизация...\n";
            $browser->visit('https://www.nywd.com/login')
                ->type('input[name="input-username"]', config('services.nywd.username'))
                ->type('input[name="input-password"]', config('services.nywd.password'))
                ->press('SIGN IN')
                ->waitForLocation('/home', 10);
            $this->setUserAgent($browser);
            echo "✅ Успешно вошли!\n";
        });

        $categoryUrls = [];
        $this->browse(function (Browser $browser) use (&$categoryUrls) {
            echo "📦 Получаем список категорий...\n";
            $browser->visit('https://www.nywd.com/home')->pause(1000);
            foreach ($browser->elements('.main-megamenu .list-featured a') as $element) {
                $onclick = $element->getAttribute('onclick');
                if (preg_match("/document\.location\.href='([^']+)'/", $onclick, $matches)) {
                    $categoryUrls[] = 'https://www.nywd.com/' . ltrim($matches[1], '/');
                }
            }
            echo "🔗 Найдено категорий: " . count($categoryUrls) . "\n";
        });

        $productLinks = [];
        foreach ($categoryUrls as $categoryUrl) {
            echo "📂 Обрабатываем категорию: $categoryUrl\n";
            $categoryProducts = $this->scrapeCategory($categoryUrl);
            $productLinks = array_merge($productLinks, $categoryProducts);
        }
        $productLinks = array_unique(array_filter($productLinks));
        echo "✅ Всего товаров: " . count($productLinks) . "\n";

        $framesData = [];
        foreach ($productLinks as $productUrl) {
            $framesData[] = $this->scrapeProduct($productUrl);
        }

        echo "✅ Итоговое количество товаров: " . count($framesData) . "\n";
        File::put(storage_path('app/frames_data.json'), json_encode($framesData, JSON_PRETTY_PRINT));
        echo "📂 Данные сохранены в storage/app/frames_data.json\n";

        echo "⏳ Время выполнения: " . round(microtime(true) - $startTime, 2) . " сек\n";
        $this->assertNotEmpty($framesData, 'Скрапинг не вернул данных.');
    }

    private function scrapeCategory($categoryUrl): array
    {
        $productLinks = [];

        $this->browse(function (Browser $browser) use ($categoryUrl, &$productLinks) {
            echo "📂 Открываем категорию: $categoryUrl\n";
            $browser->visit($categoryUrl)->pause(1500);

            $maxPages = 1;
            try {
                $paginationLinks = $browser->elements('.pagination a.activeLink');
                foreach ($paginationLinks as $link) {
                    $pageNumber = (int) trim($link->getText());
                    if ($pageNumber > $maxPages) {
                        $maxPages = $pageNumber;
                    }
                }
            } catch (NoSuchElementException $e) {
                echo "⚠️ Пагинация не найдена, продолжаем...\n";
            }

            for ($page = 1; $page <= $maxPages; $page++) {
                $pageUrl = $this->getPageUrl($categoryUrl, $page);
                echo "🔗 Открываем страницу: $pageUrl\n";

                $browser->visit($pageUrl)->pause(1000);
                try {
                    $browser->waitFor('.list-product-content', 10);
                    $productElements = $browser->elements('.list-product-content .item-product a');

                    foreach ($productElements as $anchor) {
                        $onclick = $anchor->getAttribute('onclick');

                        if (preg_match("/gotoproductpage\((\d+)\)/", $onclick, $matches)) {
                            $productId = $matches[1]; // Извлекаем ID товара
                            $productLinks[] = "https://www.nywd.com/{$productId}-cfm";
                        }
                    }
                } catch (\Exception $e) {
                    echo "⚠️ Ошибка на странице: $pageUrl\n";
                }
            }
        });

        return $productLinks;
    }

    private function scrapeProduct($productUrl): array
    {
        $productData = [];

        $this->browse(function (Browser $browser) use ($productUrl, &$productData) {
            echo "🔗 Открываем товар: $productUrl\n";
            $browser->visit($productUrl)->pause(1500);

            try {
                $browser->waitFor('.text-product', 10);
                $productData = [
                    'url' => $productUrl,
                    'brand' => $browser->text('.text-product .brand'),
                    'name' => $browser->text('.text-product h1'),
                    'upc' => trim(str_replace('UPC:', '', $browser->text('.text-product span.color'))),
                    'raw_details' => $browser->text('.text-product'),
                ];
            } catch (\Exception $e) {
                echo "⚠️ Ошибка при загрузке товара: $productUrl\n";
            }
        });

        return $productData;
    }
}

