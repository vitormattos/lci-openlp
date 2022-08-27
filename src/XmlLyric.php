<?php
namespace Hinario;

use Spatie\ArrayToXml\ArrayToXml;

class XmlLyric
{
    private Lyric $lyric;
    public function __construct(Lyric $lyric)
    {
        $this->lyric = $lyric;
    }

    private function getTitles(): array
    {
        $titles[] = [
            '__custom:title:1' => $this->lyric->getTitle(),
        ];
        if ($this->lyric->getTitleAlternative()) {
            $titles[] = [
                '__custom:title:2' => $this->lyric->getTitleAlternative(),
            ];
        }
        return $titles;
    }

    private function getAuthors(): array
    {
        $return = [];
        $sequence = 1;
        foreach ($this->lyric->getAuthors() as $type => $authors) {
            foreach ($authors as $author) {
                $return['__custom:author:' . $sequence] = [
                    '_attributes' => ['type' => $type],
                    '_value' => $author,
                ];
                $sequence++;
            }
        }
        return $return;
    }

    private function getThemes(): array
    {
        $topics = [];
        $sequence = 1;
        foreach ($this->lyric->getTopic() as $topic) {
            $topics['__custom:theme:' . $sequence] = [
                '_value' => $topic,
            ];
            $sequence++;
        }
        return $topics;
    }

    public function getSongbooks(): array
    {
        $songbooks = [];
        $songbooks['__custom:songbook:1'] = [
            '_attributes' => [
                'name' => 'LCI',
                'entry' => $this->lyric->getLci(),
            ],
        ];
        if ($this->lyric->getHpd()) {
            $songbooks['__custom:songbook:2'] = [
                '_attributes' => [
                    'name' => 'HPD',
                    'entry' => $this->lyric->getHpd(),
                ],
            ];
        }
        return $songbooks;
    }

    private function getVerses(): array
    {
        $verses = [];
        foreach ($this->lyric->getParts() as $key => $part) {
            $verses[] = [
                '__custom:verse:1' => [
                    '_attributes' => [
                        'type' => 'v',
                        'label' => $key,
                    ],
                    // '_cdata' => str_replace( "\n", '<br />', $part ),
                    '_cdata' => $part,
                ],
            ];
        }
        return $verses;
    }

    public function __toString()
    {
        $lyric = [
            // 'properties' => [
            //     'titles' => $this->getTitles(),
            // ],
            'lyrics' => $this->getVerses(),
        ];
        // if ($this->lyric->countAuthors()) {
        //     $lyric['properties']['authors'] = $this->getAuthors();
        // }
        // $lyric['properties']['songbooks'] = $this->getSongbooks();
        // if ($this->lyric->getTopic()) {
        //     $lyric['properties']['themes'] = $this->getThemes();
        // }
        return ArrayToXml::convert(
            $lyric,
            [
                'rootElementName' => 'song',
                '_attributes' => [
                    // 'xmlns' => 'http://openlyrics.info/namespace/2009/song',
                    'version' => '0.8',
                    // 'createdIn' => 'OpenLP 2.9.5',
                    // 'modifiedIn' => 'OpenLP 2.9.5',
                    // 'modifiedDate' => (new \DateTime())->format('Y-m-d\TH:i:s'),
                ],
            ],
            true,
            'UTF-8'
        );
    }
}
