
// SD stuff
#include "Sd2PinMap.h"
#include "SdInfo.h"
#include "Sd2Card.h"
#define BLOCKSIZE 512
int CS_pin = 10; // Chip Select pin for the SD interface
Sd2Card c;

// TVOut stuff
#include <TVout.h>
#include "font6x4.h" // This is the rotated font
TVout TV;

// Markov constants from the gentables.php script
#define WORDS_OFFSET 0
#define KEYS_OFFSET 92600
#define OPTIONS_OFFSET 162100
#define MAX_WORD_LENGTH 20

// Buffers / useful runtime stuff
unsigned int keyId = 0;
char thisWord[MAX_WORD_LENGTH+1]; // +1 for null termination of longest word

// Cursor coordinates
unsigned char curx = 0, cury = 0;

// Print a character on the rotated display with a delay effect.
char print_c(char c) {
  // Translate cursor position into rotated coordinates and print
  TV.print_char((cury+1)*8, 72-(curx*8), c);

  // Fake a typing-speed effect by delaying between characters
  delay(random(200) + 200);

  curx++; // Move the cursor along
  if (curx>9) { // If the cursor is too far along...
    curx=0; // reset it to the left and
    cury++; // move the cursor down.
    if (cury>11) { If the cursor is off the bottom of the screen,
      cury=11; // move it back to the last row
      TV.shift(8, LEFT); // and shift the contents of the display "up"
    }
  }
  return c;
}

// Display a string on the rotated display with a delay effect.
void print_s(char *s) {
  char c;
  while (c = *s++) {
    print_c(c);
  }
}

void setup() {
  // Prepare the TV display
  TV.begin(NTSC,120,96);
  TV.select_font(font6x4);

  // Prepare the SD interface
  pinMode(CS_pin, OUTPUT);
  c.init(SPI_HALF_SPEED, CS_pin);

  // Useful for debugging
  Serial.begin(9600);
  
  // Seed the random number generator
  randomSeed(analogRead(0));
  
  // Null-terminate thisWord (just in case we hit the longest word).
  memset(thisWord, 0, MAX_WORD_LENGTH+1);
}

// Helper function to read a chunk of data off the SD card
void getData(Sd2Card *card, unsigned long o, char *s, unsigned int n) {
  card->readData(o / BLOCKSIZE, o % BLOCKSIZE, n, (uint8_t *) s);
}

void loop() {
  unsigned int numbers[2]; // Scratch pad
  // Read from the keys table.
  getData(&c, KEYS_OFFSET + (keyId * 4), (char *) numbers, sizeof(numbers));

  // Option ids from numbers[0] to numbers[1] are available. Choose one.
  unsigned int optionId = random(numbers[1] - numbers[0] + 1) + numbers[0];

  // Read from the options table.
  getData(&c, OPTIONS_OFFSET + (optionId * 4), (char *) numbers, sizeof(numbers));

  // Numbers now contains wordId, keyId.
  keyId = numbers[1]; // Store key ID for next loop

  // Read the word we've selected and display it. (Both serial and TV.)
  getData(&c, WORDS_OFFSET + (numbers[0] * MAX_WORD_LENGTH), thisWord, MAX_WORD_LENGTH);
  Serial.print(thisWord);
  Serial.print(" ");
  print_s(thisWord);
  print_s(" ");
}

