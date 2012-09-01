<?php

/**
 * This script takes input from stdin and uses it to generate a set of tables
 * that can be used to efficiently produce Markov chain output.
 *
 * It produces three tables, all stored contiguously in the same binary file:
 * - Words. Each word is a fixed-length string, presented in order of word ID.
 *   For example, if the longest word is 20 characters, word ID 10 will be
 *   found in the file between bytes 200 and 219. Null terminators are not
 *   included but shorter words are padded up to size using nulls.
 * - Keys. Each key represents a specific sequence of $n (only tested with 2)
 *   words. For example, "is a" is a single key and the its key ID is used to
 *   represent all occurrences of "is a" in the text. Each key entry contains
 *   two little-endian 16-bit integers representing, respectively, the first
 *   and last option IDs that can follow this key. Keys are organized in
 *   sequence, starting with key ID 0, and each key entry is 4 bytes long; thus
 *   it is possible to find a key entry from its ID by calculating the offset
 *   from the end of the Words table.
 * - Options. Each option represents a possible word that can follow a given
 *   sequence of words (key). For example, the key "is a" may have option
 *   entries that refer to the words "housecat", "newsworthy", "limp", and
 *   "limp", if the input text contained the phrases "is a housecat", "is a
 *   newsworthy", and "is a limp" (the last appearing twice). Each option
 *   entry contains two little-endian 16-bit integers representing,
 *   respectively, the word ID for this option, and the key ID to use in the
 *   next iteration. (If one of the two "limp" options is chosen randomly,
 *   the word ID will refer to the single word entry for "limp" and the key ID
 *   will refer to the single key entry for "a limp", which can then be used
 *   to repeat the process for the next word.)
 *
 * Written by Alec Smecher. Please include a thank-you if you use this code
 * for anything. Distributed under the GNU GPLv2 license.
 */

// Consider a "history" of 2 words when pondering what next to say. This has
// not been tested with n <> 2; you'll at least have to modify the content
// generator.
$n = 2;

// $history stores the history as an array of word IDs. Initialize it.
$history = array_fill(0, $n, null);

// Keep a few simple statistics
$maxWordLength = $maxKeyLength = 0;

// Initialize the tables that hold the bulk of the data.
$words = array(); // word => wordIndex (inverted index)
$keys = array(); // key => keyIndex (inverted index)
$options = array(); // optionIndex => array(...option data...)

$in = fopen('php://stdin', 'r');
while (($l = fgets($in)) !== false) {
	foreach (explode(' ', $l) as $word) {
		// Find the word index in the $words list, creating it if needed
		if (!isset($words[$word])) {
			$wordIndex = count($words);
			$words[$word] = $wordIndex;
		} else {
			$wordIndex = $words[$word];
		}

		// Keep track of the longest word length
		$maxWordLength = max($maxWordLength, strlen($word));

		// Build the history into a string key
		$key = join($history, ' ');

		// Find the key index in the $keys list, creating it if needed
		if (!isset($keys[$key])) {
			$keyIndex = count($keys);
			$keys[$key] = $keyIndex;
			$maxKeyLength = max($maxKeyLength, strlen($key));
		} else {
			$keyIndex = $keys[$key];
		}

		// Store this key index as the next key index from the last iteration.
		// This is funky reference stuff.
		$someKeyIndex = $keyIndex;
		unset($someKeyIndex); // Clear the reference

		// Maintain the $options list.
		$someKeyIndex = 0; // We will back-fill this in the next iteration.
		$options[] = array(
			'wordIndex' => $wordIndex,
			'thisKeyIndex' => $keyIndex,
			'nextKeyIndex' => &$someKeyIndex // by reference
		);

		// Shift the history along for the next iteration.
		for ($i=1; $i<$n; $i++) {
			$history[$i-1] = $history[$i];
		}
		$history[$n-1] = $wordIndex;
	}
}
fclose($in);

// Use stderr for helpful output
$stderr = fopen('php://stderr', 'w');

// We've generated the tables. Now present some statistics.
fputs($stderr, 'Number of words: ' . count($words) . "\n");
fputs($stderr, 'Number of keys: ' . count($keys) . "\n");
fputs($stderr, "Maximum word length: $maxWordLength\n");
fputs($stderr, "Number of options: " . count($options) . "\n");

// Sort the options array by current key. This will mean the
// options table will contain contiguous blocks each containing all the options
// for each key.
usort($options, create_function('$a, $b', 'return $b[\'thisKeyIndex\']-$a[\'thisKeyIndex\'];'));

// Find out the first and last option IDs for each key.
$keyFirstOptions = $keyLastOptions = array();
$lastKeyId = null;
foreach ($options as $optionId => $option) {
	if ($lastKeyId != $option['thisKeyIndex']) {
		$keyFirstOptions[$option['thisKeyIndex']] = $optionId;
		$lastKeyId = $option['thisKeyIndex'];
	}
	$keyLastOptions[$option['thisKeyIndex']] = $optionId;
}

// The tables are all generated. Start saving the output to a file.

$out = fopen('markovchain.bin', 'w');

// 1. Write out the table of words. This can later be looked up starting at bytes [index * maxWordLength]
foreach ($words as $word => $wordIndex) {
	fputs($out, pack('a' . $maxWordLength, $word));
}
$wordTableEnds = ftell($out);
fputs($stderr, 'Word table: 0 - ' . ($wordTableEnds-1) . " bytes\n");

// 2. Write out the key index.
foreach ($keys as $key => $keyIndex) {
	// Find and store the first and last occurrences of the key in the option set.
	fputs($out, pack('v', $keyFirstOptions[$keyIndex]));
	fputs($out, pack('v', $keyLastOptions[$keyIndex]));
}
$keyTableEnds = ftell($out);
fputs($stderr, "Key table: $wordTableEnds - " . ($keyTableEnds-1) . " bytes\n");

// 3. Write out the options.
foreach ($options as $optionData) {
	fputs($out, pack('v', $optionData['wordIndex']));
	fputs($out, pack('v', $optionData['nextKeyIndex']));
}
$optionTableEnds = ftell($out);
fputs($stderr, "Option table: $keyTableEnds - " . ($optionTableEnds-1) . " bytes\n\n");

// Done writing output.
fclose($out);

// Display some constants that will need to be imported into the Arduino C code
// or PHP test program.
fputs($stderr, "Constants for C/C++:\n");
fputs($stderr, "#define WORDS_OFFSET 0\n");
fputs($stderr, "#define KEYS_OFFSET $wordTableEnds\n");
fputs($stderr, "#define OPTIONS_OFFSET $keyTableEnds\n");
fputs($stderr, "#define MAX_WORD_LENGTH $maxWordLength\n\n");
fputs($stderr, "Constants for PHP:\n");
fputs($stderr, "define('WORDS_OFFSET', 0);\n");
fputs($stderr, "define('KEYS_OFFSET', $wordTableEnds);\n");
fputs($stderr, "define('OPTIONS_OFFSET', $keyTableEnds);\n");
fputs($stderr, "define('MAX_WORD_LENGTH', $maxWordLength);\n\n");

// Clean up
fclose($stderr);

?>
