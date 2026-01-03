<?php
/**
 * Plant Name Generator Service
 * Generates fun, quirky, and sometimes nonsensical plant names
 */

class PlantNameGenerator
{
    private static array $prefixes = [
        'Sir', 'Lady', 'Professor', 'Captain', 'Dr.', 'Lord', 'Queen', 'King',
        'Baron', 'Duchess', 'Count', 'Princess', 'Admiral', 'General', 'Chief',
        'Big', 'Little', 'Tiny', 'Giant', 'Mighty', 'Fuzzy', 'Wiggly', 'Crispy',
        'Sparkly', 'Groovy', 'Sassy', 'Grumpy', 'Happy', 'Sleepy', 'Bouncy',
        'Mr.', 'Mrs.', 'Ms.', 'The Great', 'The Magnificent', 'The Humble'
    ];

    private static array $adjectives = [
        'Leafy', 'Green', 'Sprouty', 'Ferny', 'Mossy', 'Twiggy', 'Stemmy',
        'Fluffy', 'Spiky', 'Droopy', 'Perky', 'Bushy', 'Viney', 'Flowery',
        'Magnificent', 'Glorious', 'Humble', 'Majestic', 'Elegant', 'Dapper',
        'Quirky', 'Zany', 'Wacky', 'Snazzy', 'Jazzy', 'Funky', 'Groovy',
        'Mysterious', 'Ancient', 'Young', 'Wild', 'Tame', 'Fierce', 'Gentle',
        'Sunny', 'Shady', 'Tropical', 'Desert', 'Forest', 'Mountain', 'Ocean'
    ];

    private static array $names = [
        // Classic names
        'Fern', 'Ivy', 'Rose', 'Lily', 'Daisy', 'Violet', 'Basil', 'Sage',
        'Thyme', 'Mint', 'Pepper', 'Ginger', 'Clover', 'Olive', 'Willow',
        // Fun names
        'Planty', 'Leaferson', 'Greensworth', 'Photosynthia', 'Chlorophyll',
        'Sprouticus', 'Fernandez', 'Bloomington', 'Petalsworth', 'Rootbert',
        // Quirky names
        'McPlantface', 'Twigbert', 'Stemothy', 'Leafy McLeafface', 'Pot-ato',
        'Succulent Sue', 'Cactus Jack', 'Fern Gully', 'Palm Pilot', 'Aloe-ha',
        // Silly names
        'Plantonio', 'Ferdinand', 'Gertrude', 'Bartholomew', 'Penelope',
        'Reginald', 'Beatrice', 'Winston', 'Mildred', 'Eugene', 'Cornelius',
        'Mortimer', 'Prudence', 'Archibald', 'Geraldine', 'Humphrey',
        // Pop culture inspired
        'Groot Jr.', 'Baby Groot', 'Plant Skywalker', 'Obi-Wan Kenobi-a',
        'Darth Vader(s Fern)', 'Indiana Stems', 'James Frond', 'Fern Solo'
    ];

    private static array $suffixes = [
        'III', 'Jr.', 'the First', 'the Second', 'the Third',
        'Esquire', 'PhD', 'MD', 'the Magnificent', 'the Wise',
        'the Green', 'the Leafy', 'of the Windowsill', 'of the Garden',
        'the Unkillable', 'the Survivor', 'the Thirsty', 'the Dramatic'
    ];

    private static array $fullNames = [
        'Sir Plantsalot',
        'Fernie Sanders',
        'Leaf Erikson',
        'Plantonio Banderas',
        'Morgan Treeman',
        'Elvis Parsley',
        'Justin Timber-plant',
        'Britney Spears-mint',
        'Aloe Vera Wang',
        'Christofern Columbus',
        'Abraham Linkon-the-Sill',
        'Vincent Van Grow',
        'Leonardo DiCapri-corn',
        'Audrey Hep-fern',
        'Kale-vin Klein',
        'Plant Eastwood',
        'Snoop Frog',
        'Cardi B-egonia',
        'Post Ma-plant',
        'Ariana Grande-flora',
        'Fern-ando Alonso',
        'Tom Petty Flowers',
        'Plant-man',
        'Spider-plant',
        'The Incredible Hulk-ing Fern',
        'Planty McPlantface',
        'Mr. Photosynthesis',
        'Professor Greenthumb',
        'Dr. Chlorophyll',
        'Captain Leafbeard',
        'The Notorious P.L.A.N.T.',
        'Sherlock Stems',
        'Gandalf the Green',
        'Frodo Bud-gins',
        'Bilbo Bud-gins',
        'Samwise Gam-tree',
        'Legolas Greenleaf',
        'Tree-beard Jr.',
        'Severus Snip',
        'Albus Dumble-fern',
        'Harry Pot-ter',
        'Hermione Grow-nger'
    ];

    /**
     * Generate a random plant name
     */
    public static function generate(): array
    {
        $styles = ['full', 'prefix_name', 'adj_name', 'prefix_adj_name', 'name_suffix'];
        $style = $styles[array_rand($styles)];

        // 30% chance to use a pre-made full name
        if (random_int(1, 100) <= 30) {
            return [
                'name' => self::$fullNames[array_rand(self::$fullNames)],
                'style' => 'curated'
            ];
        }

        $name = match($style) {
            'full' => self::generateFull(),
            'prefix_name' => self::generatePrefixName(),
            'adj_name' => self::generateAdjName(),
            'prefix_adj_name' => self::generatePrefixAdjName(),
            'name_suffix' => self::generateNameSuffix(),
            default => self::generatePrefixName()
        };

        return [
            'name' => $name,
            'style' => $style
        ];
    }

    /**
     * Generate multiple name suggestions
     */
    public static function generateMultiple(int $count = 5): array
    {
        $names = [];
        $attempts = 0;
        $maxAttempts = $count * 3;

        while (count($names) < $count && $attempts < $maxAttempts) {
            $result = self::generate();
            if (!in_array($result['name'], $names)) {
                $names[] = $result['name'];
            }
            $attempts++;
        }

        return $names;
    }

    private static function generateFull(): string
    {
        $prefix = self::$prefixes[array_rand(self::$prefixes)];
        $adj = self::$adjectives[array_rand(self::$adjectives)];
        $name = self::$names[array_rand(self::$names)];
        $suffix = self::$suffixes[array_rand(self::$suffixes)];

        return "$prefix $adj $name $suffix";
    }

    private static function generatePrefixName(): string
    {
        $prefix = self::$prefixes[array_rand(self::$prefixes)];
        $name = self::$names[array_rand(self::$names)];
        return "$prefix $name";
    }

    private static function generateAdjName(): string
    {
        $adj = self::$adjectives[array_rand(self::$adjectives)];
        $name = self::$names[array_rand(self::$names)];
        return "$adj $name";
    }

    private static function generatePrefixAdjName(): string
    {
        $prefix = self::$prefixes[array_rand(self::$prefixes)];
        $adj = self::$adjectives[array_rand(self::$adjectives)];
        $name = self::$names[array_rand(self::$names)];
        return "$prefix $adj $name";
    }

    private static function generateNameSuffix(): string
    {
        $name = self::$names[array_rand(self::$names)];
        $suffix = self::$suffixes[array_rand(self::$suffixes)];
        return "$name $suffix";
    }
}
