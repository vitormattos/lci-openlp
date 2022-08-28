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
                    '_cdata' => $part,
                ],
            ];
        }
        return $verses;
    }

    public function __toString()
    {
        $lyric = [
            'lyrics' => $this->getVerses(),
        ];
        return ArrayToXml::convert(
            $lyric,
            [
                'rootElementName' => 'song',
                '_attributes' => [
                    'version' => '0.8',
                ],
            ],
            true,
            'UTF-8'
        );
    }
}
