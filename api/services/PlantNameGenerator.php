<?php
/**
 * Plant Name Generator Service
 * Generates fun, quirky, and sometimes nonsensical plant names
 */

class PlantNameGenerator {

	private static array $prefixes = array(
		'Sir',
		'Lady',
		'Professor',
		'Captain',
		'Dr.',
		'Lord',
		'Queen',
		'King',
		'Baron',
		'Duchess',
		'Count',
		'Princess',
		'Admiral',
		'General',
		'Chief',
		'Big',
		'Little',
		'Tiny',
		'Giant',
		'Mighty',
		'Fuzzy',
		'Wiggly',
		'Crispy',
		'Sparkly',
		'Groovy',
		'Sassy',
		'Grumpy',
		'Happy',
		'Sleepy',
		'Bouncy',
		'Mr.',
		'Mrs.',
		'Ms.',
		'The Great',
		'The Magnificent',
		'The Humble',
		'The Curious',
		'The Brave',
		'The Quiet',
		'The Bold',
		'The Shy',
		'Old',
		'Young',
		'Grand',
		'Mini',
		'Ultra',
		'Mega',
		'Saint',
		'Reverend',
		'Mayor',
		'Coach',
		'Boss',
		'Buddy',
	);

	private static array $adjectives = array(
		'Leafy',
		'Green',
		'Sprouty',
		'Ferny',
		'Mossy',
		'Twiggy',
		'Stemmy',
		'Fluffy',
		'Spiky',
		'Droopy',
		'Perky',
		'Bushy',
		'Viney',
		'Flowery',
		'Magnificent',
		'Glorious',
		'Humble',
		'Majestic',
		'Elegant',
		'Dapper',
		'Quirky',
		'Zany',
		'Wacky',
		'Snazzy',
		'Jazzy',
		'Funky',
		'Groovy',
		'Mysterious',
		'Ancient',
		'Young',
		'Wild',
		'Tame',
		'Fierce',
		'Gentle',
		'Sunny',
		'Shady',
		'Tropical',
		'Desert',
		'Forest',
		'Mountain',
		'Ocean',
		'Rusty',
		'Velvety',
		'Glossy',
		'Dusty',
		'Prickly',
		'Squishy',
		'Bendy',
		'Curly',
		'Stretchy',
		'Thirsty',
		'Dramatic',
		'Lazy',
		'Chill',
		'Moody',
		'Calm',
		'Spirited',
		'Wandering',
	);

	private static array $names = array(
		// Plant / nature names
		'Fern',
		'Ivy',
		'Rose',
		'Lily',
		'Daisy',
		'Violet',
		'Basil',
		'Sage',
		'Thyme',
		'Mint',
		'Pepper',
		'Ginger',
		'Clover',
		'Olive',
		'Willow',
		'Juniper',
		'Maple',
		'Aspen',
		'Hazel',
		'Oakley',
		'Poppy',
		'Lavender',
		'Marigold',
		'Petunia',
		'Zinnia',
		'Begonia',
		'Camellia',
		'Azalea',
		'Peony',
		'Iris',
		'Lotus',
		'Orchid',
		'Yucca',
		'Agave',
		'Aloe',

		// Fun / pun names
		'Planty',
		'Leaferson',
		'Greensworth',
		'Photosynthia',
		'Chlorophyll',
		'Sprouticus',
		'Fernandez',
		'Bloomington',
		'Petalsworth',
		'Rootbert',
		'Budrick',
		'Stemuel',
		'Leaford',
		'Growbert',
		'Sprigley',
		'Greenjamin',
		'Budley',
		'Phyllis',
		'Sprouton',

		// Quirky names
		'McPlantface',
		'Twigbert',
		'Stemothy',
		'Leafy McLeafface',
		'Pot-ato',
		'Succulent Sue',
		'Cactus Jack',
		'Fern Gully',
		'Palm Pilot',
		'Aloe-ha',
		'Plantzilla',
		'Bud Lightyear',
		'Shrub Norris',
		'Leaf Phoenix',

		// Real human first names (100+)
		'James',
		'John',
		'Robert',
		'Michael',
		'William',
		'David',
		'Richard',
		'Joseph',
		'Thomas',
		'Charles',
		'Christopher',
		'Daniel',
		'Matthew',
		'Anthony',
		'Mark',
		'Donald',
		'Steven',
		'Paul',
		'Andrew',
		'Joshua',
		'Kenneth',
		'Kevin',
		'Brian',
		'George',
		'Edward',
		'Ronald',
		'Timothy',
		'Jason',
		'Jeffrey',
		'Ryan',
		'Jacob',
		'Gary',
		'Nicholas',
		'Eric',
		'Stephen',
		'Jonathan',
		'Larry',
		'Justin',
		'Scott',
		'Brandon',
		'Benjamin',
		'Samuel',
		'Gregory',
		'Frank',
		'Alexander',
		'Raymond',
		'Patrick',
		'Jack',
		'Dennis',
		'Jerry',

		'Mary',
		'Patricia',
		'Jennifer',
		'Linda',
		'Elizabeth',
		'Barbara',
		'Susan',
		'Jessica',
		'Sarah',
		'Karen',
		'Nancy',
		'Lisa',
		'Margaret',
		'Betty',
		'Sandra',
		'Ashley',
		'Dorothy',
		'Kimberly',
		'Emily',
		'Donna',
		'Michelle',
		'Carol',
		'Amanda',
		'Melissa',
		'Deborah',
		'Stephanie',
		'Rebecca',
		'Laura',
		'Sharon',
		'Cynthia',
		'Kathleen',
		'Amy',
		'Shirley',
		'Angela',
		'Helen',
		'Anna',
		'Brenda',
		'Pamela',
		'Nicole',
		'Emma',
		'Samantha',
		'Katherine',
		'Christine',
		'Debra',
		'Rachel',
		'Catherine',
		'Carolyn',
		'Janet',
		'Ruth',
	);

	private static array $suffixes = array(
		'III',
		'Jr.',
		'the First',
		'the Second',
		'the Third',
		'Esquire',
		'PhD',
		'MD',
		'the Magnificent',
		'the Wise',
		'the Green',
		'the Leafy',
		'of the Windowsill',
		'of the Garden',
		'the Unkillable',
		'the Survivor',
		'the Thirsty',
		'the Dramatic',
		'the Resilient',
		'the Overwatered',
		'of the Kitchen',
	);

	private static array $fullNames = array(
		'Sir Plantsalot',
		'Fernie Sanders',
		'Leaf Erikson',
		'Plantonio Banderas',
		'Morgan Treeman',
		'Elvis Parsley',
		'Justin Timber-plant',
		'Aloe Vera Wang',
		'Vincent Van Grow',
		'Leonardo DiCapri-corn',
		'Audrey Hep-fern',
		'Plant Eastwood',
		'Professor Greenthumb',
		'Dr. Chlorophyll',
		'Captain Leafbeard',
		'Sherlock Stems',
		'Gandalf the Green',
		'Samwise Gam-tree',
		'Legolas Greenleaf',
		'Harry Pot-ter',
	);

	/**
	 * Generate a random plant name.
	 */
	public static function generate(): array {
		$styles = array( 'full', 'prefix_name', 'adj_name', 'prefix_adj_name', 'name_suffix' );
		$style  = $styles[ array_rand( $styles ) ];

		if ( random_int( 1, 100 ) <= 30 ) {
			return array(
				'name'  => self::$fullNames[ array_rand( self::$fullNames ) ],
				'style' => 'curated',
			);
		}

		$name = match ($style) {
			'full' => self::generateFull(),
			'prefix_name' => self::generatePrefixName(),
			'adj_name' => self::generateAdjName(),
			'prefix_adj_name' => self::generatePrefixAdjName(),
			'name_suffix' => self::generateNameSuffix(),
			default => self::generatePrefixName(),
		};

		return array(
			'name'  => $name,
			'style' => $style,
		);
	}

	/**
	 * Generate multiple name suggestions.
	 */
	public static function generateMultiple( int $count = 5 ): array {
		$names       = array();
		$attempts    = 0;
		$maxAttempts = $count * 3;

		while ( count( $names ) < $count && $attempts < $maxAttempts ) {
			$result = self::generate();
			if ( ! in_array( $result['name'], $names, true ) ) {
				$names[] = $result['name'];
			}
			$attempts++;
		}

		return $names;
	}

	private static function generateFull(): string {
		$prefix = self::$prefixes[ array_rand( self::$prefixes ) ];
		$adj    = self::$adjectives[ array_rand( self::$adjectives ) ];
		$name   = self::$names[ array_rand( self::$names ) ];
		$suffix = self::$suffixes[ array_rand( self::$suffixes ) ];

		return "$prefix $adj $name $suffix";
	}

	private static function generatePrefixName(): string {
		$prefix = self::$prefixes[ array_rand( self::$prefixes ) ];
		$name   = self::$names[ array_rand( self::$names ) ];

		return "$prefix $name";
	}

	private static function generateAdjName(): string {
		$adj  = self::$adjectives[ array_rand( self::$adjectives ) ];
		$name = self::$names[ array_rand( self::$names ) ];

		return "$adj $name";
	}

	private static function generatePrefixAdjName(): string {
		$prefix = self::$prefixes[ array_rand( self::$prefixes ) ];
		$adj    = self::$adjectives[ array_rand( self::$adjectives ) ];
		$name   = self::$names[ array_rand( self::$names ) ];

		return "$prefix $adj $name";
	}

	private static function generateNameSuffix(): string {
		$name   = self::$names[ array_rand( self::$names ) ];
		$suffix = self::$suffixes[ array_rand( self::$suffixes ) ];

		return "$name $suffix";
	}
}
