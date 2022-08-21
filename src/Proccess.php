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

class Proccess
{
    private string $assetsPath;

    public function __construct()
    {
        $this->assetsPath = realpath(dirname(__FILE__) . '/../assets/');
    }
    public function getSummary(): array
    {
        if (!file_exists($this->assetsPath . '/summary.json')) {
            $html = file_get_contents('https://www.luteranos.com.br/conteudo/livro-de-canto-da-ieclb-por-numeracao');
            // Replace invisible chars
            $html = str_replace(' ', ' ', $html);
            $html = str_replace('&nbsp;', ' ', $html);
            $crawler = new Crawler($html);
            $rows = $crawler
                ->filter('article table>tbody>tr')
                ->each(function(Crawler $tr, $i) {
                    $row = $tr->filter('td')
                        ->each(function(Crawler $td, $j) {
                            if ($j === 0) {
                                $link = $td->filter('a');
                                if ($link->count()) {
                                    return [
                                        $link->link()->getUri(),
                                        $link->text(),
                                    ];
                                }
                                return [
                                    '',
                                    $td->text(),
                                ];
                            }
                            return $td->text();
                        });
                    return [
                        'url' => trim($row[0][0]),
                        'title' => trim($row[0][1]),
                        'LCI' => trim($row[1]),
                        'HPD' => trim($row[2]),
                        'topic' => trim($row[3]),
                    ];
                });
            unset($rows[0]);
            file_put_contents($this->assetsPath . '/summary.json', json_encode(array_values($rows)));
        }
        $summary = json_decode(file_get_contents($this->assetsPath . '/summary.json'), true);
        $summary = array_map(fn($row) => new Lyric($row, $this->assetsPath), $summary);
        $this->downloadLyrics($summary);
        return $summary;
    }

    private function downloadLyrics($summary): void
    {
        foreach ($summary as $lyric) {
            if ($lyric->getUrl()) {
                $lyric->setHtmlfile($this->assetsPath . '/LCI/html/' . $lyric->getLci() . '.html');
                if (file_exists($lyric->getHtmlfile())) {
                    continue;
                }
                $lyric->downloadAssync();
            }
        }
    }

    public function parseLyrics(): void
    {
        $summary = $this->getSummary();
        foreach ($summary as $lyric) {
            $lyric->parseAll();
        }
    }
}
