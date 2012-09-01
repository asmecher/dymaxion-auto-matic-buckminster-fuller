<?php

/**
 * Generate Markov chain output until forcibly stopped.
 * This takes a set of data from markovchain.bin, which can be generated
 * using the gentables.php script.
 *
 * Written by Alec Smecher. Please include a thank-you if you use this code
 * for anything. Distributed under the GNU GPLv2 license.
 */

// These constants need to correspond to those given by gentables.php
define('WORDS_OFFSET', 0);
define('KEYS_OFFSET', 92600);
define('OPTIONS_OFFSET', 162100);
define('MAX_WORD_LENGTH', 20);

($fp = fopen('markovchain.bin', 'rb')) || die("Unable to open markovchain.bin; did you generate it?\n");
$keyId = 0; // Start at the beginning. (Where else?)

while (true) {
	// Get the information for the current key.
	fseek($fp, KEYS_OFFSET + ($keyId * 4));
	extract(unpack('vfirstOptionId/vlastOptionId', fread($fp, 4)));

	// From the options available for this key, choose one.
	$optionId = rand($firstOptionId, $lastOptionId);

	// Get the information for the chosen option (including the next key).
	fseek($fp, OPTIONS_OFFSET + ($optionId * 4));
	extract(unpack('vwordId/vkeyId', fread($fp, 4)));

	// Display the word we just chose.
	fseek($fp, WORDS_OFFSET + ($wordId * MAX_WORD_LENGTH));
	echo trim(fread($fp, MAX_WORD_LENGTH)) . ' ';
}

fclose($fp);

?>
