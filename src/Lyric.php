<?php
/**
 * @copyright Copyright (c) 2022, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace Hinario;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;

class Lyric
{
    const UNKNOWN_AUTHOR = 'Autoria desconheecida';
    private string $assetsPath;
    private string $lci;
    private string $htmlFile;
    private string $url;
    private array $topics = [];
    private array $parts = [];
    private string $title;
    private ?string $titleAlternative;
    private ?string $hpd;
    private ?Crawler $crawler;
    private $resource;
    private ?int $id;
    private array $authors = [
        'word' => [],
        'music' => [],
        'translation' => [],
        'words+music' => [],
    ];
    public function __construct(array $row, $assetsPath)
    {
        $this->assetsPath = $assetsPath;
        $this->lci = $row['LCI'];
        $this->htmlFile = $this->assetsPath . '/LCI/html/' . $this->lci . '.html';
        $this->url = $row['url'];
        $this->title = $row['title'];
        $this->titleAlternative = null;
        $this->hpd = $row['HPD'] ?? null;
        $this->setTopic($row['topic']);
        $this->crawler = null;
        $this->id = null;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAssetsPath(): string
    {
        return $this->assetsPath;
    }

    public function getLci(): string
    {
        return $this->lci;
    }

    public function getHtmlFile(): string
    {
        return $this->htmlFile;
    }

    public function setHtmlfile($htmlFile): void
    {
        $this->htmlFile = $htmlFile;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTitleAlternative(): ?string
    {
        return $this->titleAlternative;
    }

    public function getHpd(): ?string
    {
        return $this->hpd;
    }

    public function getTopic(): array
    {
        return $this->topics;
    }

    private function setTopic($topic): void {
        if (!in_array($topic, $this->topics)) {
            $this->topics[] = $topic;
        }
    }

    public function getResource()
    {
        if (!is_resource($this->resource)) {
            $this->resource = fopen($this->getHtmlfile(), 'a+');
        }
        return $this->resource;
    }

    public function downloadAssync(): void
    {
        new AsyncResponse(
            HttpClient::create(),
            'GET',
            $this->getUrl(),
            [],
            function ($chunk, AsyncContext $context) {
                if ($chunk->isLast()) {
                    $this->shrinkHtmlFile();
                    $this->parseAll();
                    fclose($this->getResource());
                    yield $chunk;
                };
                $content = $chunk->getContent();
                fwrite($this->getResource(), $content);
            }
        );
    }

    private function shrinkHtmlFile(): void
    {
        $text = $this->getBody();
        ftruncate($this->getResource(), 0);
        fwrite($this->getResource(), $text);
    }

    private function getBody(): string
    {
        $crawler = $this->getCrawler();
        if ($crawler->filter('#text-print')->count()) {
            $this->crawler = $crawler->filter('#text-print');
            return $this->crawler->filter('#text-print')->html();
        }
        return $this->crawler->html();
    }

    private function getCrawler(): ?Crawler
    {
        if (is_null($this->crawler)) {
            if (!file_exists($this->assetsPath . '/LCI/html/' . $this->getLci() . '.html')) {
                if (!$this->getUrl()) {
                    return null;
                }
                $this->downloadSync();
            }
            rewind($this->getResource());
            $content = fread($this->getResource(), filesize($this->getHtmlfile()));
            $this->crawler = new Crawler($content);
        }
        return $this->crawler;
    }

    private function downloadSync(): void
    {
        $content = file_get_contents($this->getUrl());
        fwrite($content, $content);
        $this->shrinkHtmlFile();
        $this->parseAll();
        fclose($this->getResource());
    }

    public function parseAll(): void
    {
        $this->getAuthors();
        $this->parseTitleAlternative();
        $this->translateUnkownAuthor();
        $this->parseSong();
    }

    public function getParts(): array
    {
        return $this->parts;
    }

    private function parseSong(): void
    {
        $crawler = $this->getCrawler();
        if (!$crawler) {
            return;
        }
        $list = explode('<hr>', $crawler->filter('article')->html());
        $parts = (new Crawler($list[0]))
            ->filter('p')
            ->each(function($part) {
                $part = $part->html();
                $part = $this->sanitizeText($part);
                $part = strip_tags($part);
                $part = preg_replace('/^\d+\. ?/', '', $part);
                return $part;
            });
        // remove empty parts
        $this->parts = array_filter($parts, fn ($s) => strlen($s));
    }

    private function sanitizeText(string $string): string
    {
        // Replace multiple spaces
        $string = preg_replace('/[\t ]+/', ' ', $string);
        // remove whitespace from end of row
        $string = preg_replace('/[\t ]+$/', ' ', $string);
        // remove whitespace from begin of row
        $string = preg_replace('/\n /', "\n", $string);
        // remove double \n
        $string = preg_replace('/\n+/', "\n", $string);
        // Trim whitespace
        $string = trim($string);
        $string = trim($string, chr(194).chr(160));
        $string = trim($string);

        return $string;
    }

    private function parseTitleAlternative(): void
    {
        $crawler = $this->getCrawler();
        if (!$crawler) {
            return;
        }
        $this->titleAlternative = $crawler->filter('header h1')->text();
        if ($this->title === $this->titleAlternative) {
            $this->titleAlternative = null;
        }
    }

    public function getAuthors(): array
    {
        if ($this->countAuthors()) {
            return $this->authors;
        }
        $crawler = $this->getCrawler();
        if (!$crawler || !$crawler->filter('hr')->count()) {
            return $this->authors;
        }
        $list = explode('<hr>', $crawler->filter('article')->html());
        for ($i = count($list) -1; $i >= 1; $i--) {
            $string = $list[$i];
            $string = strip_tags($string);
            $string = $this->cleanLikeGoHorse($string);

            $string = $this->parseAuthors($string, 'words+music');
            $string = $this->parseAuthors($string, 'words');
            $string = $this->parseAuthors($string, 'music');
            $string = $this->parseAuthors($string, 'translation');
            $string = $this->parseAuthors($string, 'unknown');
            $this->removeUnusedAuthorTypes();
            $this->removeBibleReferences();

            if ($this->countAuthors()) {
                // Only to debug, if implement log, move this to log:
                // foreach ($this->authors as $type => $authors) {
                //     if ($authors) {
                //         echo $type . '##' . implode('##', $authors) . PHP_EOL;
                //     }
                // }
                break;
            }
        }
        return $this->authors;
    }

    private function cleanLikeGoHorse(string $string): string
    {
        $string = $this->sanitizeText($string);

        // Go Horse
        $string = str_replace('Johnson Oatman, Jr', 'Johnson Oatman Jr', $string);
        $string = str_replace('Musiguandu, ADL', 'Musiguandu ADL', $string);
        if (strpos($string, 'da Missa Popular Salvadorenha') !== false) {
            $this->setTopic('Missa Popular Salvadorenha');
        }
        $string = str_replace(', da Missa Popular Salvadorenha', '', $string);
        if (strpos($string, 'Igreja Católica Romana') !== false) {
            $this->setTopic('Igreja Católica Romana');
        }
        $string = str_replace('Igreja Católica Romana, Brasil, pós-Vaticano II', 'Igreja Católica Romana - Brasil - pós-Vaticano II', $string);
        $string = str_replace('Nabor Nunes Filho, 1944-2013', 'Nabor Nunes Filho', $string);
        $string = str_replace('Robert Hawkey Moreton (1844-1917)', 'Robert Hawkey Moreton', $string);
        $string = str_replace('Rev. Robert Lowry (1826-1899)', 'Rev. Robert Lowry', $string);
        $string = str_replace('Gilmer Torres Ruiz, Grupo SIEMBRA', 'Gilmer Torres Ruiz - Grupo SIEMBRA', $string);
        $string = str_replace('Erfurt, século XV', 'Erfurt', $string);
        $string = str_replace('Criação Coletiva, Matanzas', 'Matanzas', $string);
        $string = str_replace('Johann Lindemann - Cyriakus Schneegass', 'Johann Lindemann, Cyriakus Schneegass', $string);
        $string = str_replace("Autoria – Oziel Campos de Oliveira Jr.", "Autoria: Oziel Campos de Oliveira Jr.", $string);
        $string = str_replace('(Cânone 2v)', '', $string);
        $string = str_replace('Letra: 95.1', '', $string);
        if (strpos($string, 'Salmo 91.1,2') !== false) {
            $this->setTopic('Salmo 91.1,2');
        }
        $string = str_replace('Letra; Salmo 91.1,2', '', $string);
        return $string;
    }

    public function countAuthors(): int
    {
        $total = 0;
        foreach ($this->authors as $authors) {
            $total += count($authors);
        }
        return $total;
    }

    public function parseAuthors(string $string, $type): string
    {
        $name = '(?<name>([\/\'\-\(\)\da-záÁÄäâãéèêÉẽêíÖóöôõúüçÇ,. ]+)+)';
        $separator = '[  ]*[\/;:\-][  ]*';
        $music = '(arranjo|me(lo|ol|l)dia|melida|estribilho|música)';
        $patternsDictionary = [
            'words' => [
                '/((autor(i?a)? da )?(\da\.? )?(le[tg]ra|estrofe|autor(ia)?))' . $separator . $name . '/i',
                '/(autores)' . $separator . $name . '/i',
            ],
            'music' => ['/((autor(i?a)? d[a|o] )?' . $music . ')' . $separator . $name . '/i'],
            'translation' => [
                '/(adaptado)' . $separator . $name . '/i',
                '/((autor(i?a)? da )?(tradução|tradutora?)( do latim)?)' . $separator . $name . '/i',
            ],
            'words+music' => [
                '/(L e M)' . $separator . $name . '/i',
                '/((autor(i?a)? da )?(texto|let?(r)+a|versão|arranjo|est) e (da )?' . $music . ')' . $separator . $name . '/i',
            ],
            'unknown' => ['/(?<name>autoria desc?onhecida)/i'],
        ];
        $patterns = $patternsDictionary[$type];
        if ($type === 'unknown') {
            $type = 'words+music';
        }
        $blockList = [
            'Alemanha, Século VII',
            'Argentina, Rio de La Plata',
            'Hino latino medieval, séc. VI',
            'Hino latino, séc. VII',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $string, $matches)) {
                //  Multiple authors
                foreach ($matches['name'] as $i => $match) {
                    if (in_array($match, $blockList)) {
                        continue;
                    }
                    $separator = '\/,\|';
                    $explode = preg_split('/( [' . $separator . 'e] |[' . $separator . '])/', $match);
                    if (count($explode) > 1) {
                        foreach ($explode as $author) {
                            if (trim($author)) {
                                $this->authors[$type][] = trim($author);
                            }
                        }
                    } elseif (trim($match)) {
                        $this->authors[$type][] = trim($match);
                    }
                    $string = str_replace($matches[$i], '', $string);
                }
            }
        }
        return $string;
    }

    private function removeBibleReferences(): void
    {
        $books = [
            'Lucas','Apocalipse','Salmo'
        ];
        foreach ($this->authors as $type => $authors) {
            if (!$authors) {
                continue;
            }
            foreach ($books as $book) {
                $authors = array_filter($authors, function($author) use ($book) {
                    $found = preg_match('/' . $book . ' (\.,\d)*?/i', $author);
                    if ($found) {
                        $this->setTopic($author);
                        return false;
                    }
                    return true;
                });
            }
            $this->authors[$type] = $authors;
        }
    }

    private function translateUnkownAuthor(): void
    {
        $dictionary = [
            'Desconhecida',
            'Desconhecido',
            'Origem incerta',
        ];
        foreach ($this->authors as $type => $authors) {
            foreach ($authors as $key => $author) {
                if (in_array($author, $dictionary)) {
                    $this->authors[$type][$key] = self::UNKNOWN_AUTHOR;
                }
            }
        }
    }

    private function removeUnusedAuthorTypes(): void
    {
        $this->authors = array_filter($this->authors, fn($authors) => count($authors));
    }

    public function __toString()
    {
        $xmlLyric = new XmlLyric($this);
        return (string) $xmlLyric;
    }
}
