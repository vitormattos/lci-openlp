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
    private \PDO $pdo;

    public function __construct()
    {
        $this->assetsPath = realpath(dirname(__FILE__) . '/../assets/');
        $this->pdo = new \PDO('sqlite:assets/songs.sqlite', '', '', [\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }
    public function getSummary(): array
    {
        if (!file_exists($this->assetsPath . '/summary.json')) {
            $html = file_get_contents('https://www.luteranos.com.br/conteudo/livro-de-canto-da-ieclb-por-numeracao');
            // Replace invisible chars
            $html = str_replace('&nbsp;', ' ', $html);
            $html = str_replace(' ', ' ', $html);
            $crawler = new Crawler($html);
            $rows = $crawler
                ->filter('article table>tbody>tr')
                ->each(function (Crawler $tr, $i) {
                    $row = $tr->filter('td')
                        ->each(function (Crawler $td, $j) {
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
        $summary = array_map(fn ($row) => new Lyric($row, $this->assetsPath), $summary);
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

    /**
     * Parse and return the songs
     *
     * @return Lyric[]
     */
    public function parseSongs(): array
    {
        $summary = $this->getSummary();
        foreach ($summary as $lyric) {
            $lyric->parseAll();
        }
        return $summary;
    }

    public function populdateDatabase(): void
    {
        $songs = $this->parseSongs();


        $stmt = $this->pdo->query('DELETE FROM songs');
        $stmt = $this->pdo->query('VACUUM');
        foreach ($songs as $lyric) {
            $this->insertSongs($lyric);
        }

        $stmt = $this->pdo->query('DELETE FROM authors');
        $stmt = $this->pdo->query('DELETE FROM authors_songs');
        $stmt = $this->pdo->query('VACUUM');
        foreach ($songs as $lyric) {
            $this->insertAuthors($lyric);
        }

        $stmt = $this->pdo->query('DELETE FROM topics');
        $stmt = $this->pdo->query('VACUUM');
        foreach ($songs as $lyric) {
            $this->insertTopics($lyric);
        }

        $stmt = $this->pdo->query('DELETE FROM songs_songbooks');
        $stmt = $this->pdo->query('VACUUM');
        foreach ($songs as $lyric) {
            if (!$lyric->getParts()) {
                continue;
            }
            $stmt = $this->pdo->prepare('INSERT INTO songs_songbooks (songbook_id, song_id, entry) VALUES(?,?,?)');
            $stmt->execute([1, $lyric->getId(), $lyric->getLci()]);
            if ($lyric->getHpd()) {
                $stmt = $this->pdo->prepare('INSERT INTO songs_songbooks (songbook_id, song_id, entry) VALUES(?,?,?)');
                $stmt->execute([2, $lyric->getId(), $lyric->getHpd()]);
            }
        }
    }

    private function insertSongs(Lyric $lyric): void
    {
        if (!$lyric->getParts()) {
            return;
        }
        $data['title'] = $lyric->getTitle();
        $data['alternate_title'] = $lyric->getTitleAlternative() ?? '';
        $data['lyrics'] = (string) $lyric;
        $data['verse_order'] = '';
        $data['copyright'] = '';
        $data['comments'] = '';
        $data['ccli_number'] = '';
        $data['theme_name'] = null;
        $data['search_title'] = $lyric->getTitle() . '@' . $lyric->getTitleAlternative();
        $data['search_lyrics'] = implode(' ', array_map(
            fn ($part) => str_replace("\n", ' ', $part),
            $lyric->getParts()
        ));
        $data['create_date'] = (new \DateTime())->format('Y-m-d H:i:s');
        $data['last_modified'] = (new \DateTime())->format('Y-m-d H:i:s');
        $data['temporary'] = 0;
        $insert = 'INSERT INTO songs (' .
            implode(',', array_keys($data)) .
            ') VALUES (' .
            implode(',', array_fill(0, count($data), '?')) .
            ')';
        $stmt = $this->pdo->prepare($insert);
        $stmt->execute(array_values($data));
        $lyric->setId($this->pdo->lastInsertId());
    }

    private function insertTopics(Lyric $lyric): void
    {
        if (!$lyric->getParts()) {
            return;
        }
        foreach ($lyric->getTopic() as $topic) {
            $stmt = $this->pdo->prepare('SELECT id FROM topics WHERE name = ?');
            $stmt->execute([$topic]);
            $topicId = $stmt->fetchColumn();
            if (!$topicId) {
                $stmt = $this->pdo->prepare('INSERT INTO topics (name) VALUES(?)');
                $stmt->execute([$topic]);
                $topicId = $this->pdo->lastInsertId();
            }
            $stmt = $this->pdo->prepare('INSERT INTO songs_topics (song_id, topic_id) VALUES(?,?)');
            try {
                $stmt->execute([$lyric->getId(), $topicId]);
            } catch (\Throwable $th) {
            }
        }
    }

    private function insertAuthors(Lyric $lyric): void
    {
        if (!$lyric->getParts()) {
            return;
        }
        foreach ($lyric->getAuthors() as $type => $authors) {
            foreach ($authors as $displayName) {
                $stmt = $this->pdo->prepare('SELECT id FROM authors WHERE display_name = ?');
                $stmt->execute([$displayName]);
                $authorId = $stmt->fetchColumn();
                if (!$authorId) {
                    $stmt = $this->pdo->prepare('INSERT INTO authors (first_name, last_name, display_name) VALUES(?,?,?)');
                    $parts = explode(' ', $displayName);
                    $last = array_pop($parts);
                    $first = implode(' ', $parts);
                    $stmt->execute([
                        $first,
                        $last,
                        $displayName,
                    ]);
                    $authorId = $this->pdo->lastInsertId();
                }
                $stmt = $this->pdo->prepare('INSERT INTO authors_songs (author_id, song_id, author_type) VALUES(?,?,?)');
                $stmt->execute([
                    $authorId,
                    $lyric->getId(),
                    $type,
                ]);
            }
        }
    }
}
