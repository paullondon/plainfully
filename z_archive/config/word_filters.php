<?php declare(strict_types=1);

/**
 * Plainfully — Offensive / sensitive word filters
 *
 * KEY   = lowercase word/pattern to search for
 * VALUE = safe replacement
 *
 * You may add/remove words freely.
 */

return [

    // Strong profanity
    'fuck'        => 'f**k',
    'fucking'     => 'f**king',
    'fucker'      => 'f**ker',
    'motherfucker'=> 'motherf**ker',
    'shit'        => 's**t',
    'shitty'      => 's**tty',
    'bullshit'    => 'bulls**t',
    'cunt'        => 'c**t',
    'cunting'     => 'c**ting',
    'twat'        => 'tw*t',
    'twatting'    => 'tw*tting',

    // Mild insults
    'bastard'     => 'b******',
    'asshole'     => 'a**hole',
    'arsehole'    => 'a**ehole',
    'wanker'      => 'w****r',
    'tosser'      => 't*****',

    // Sexual explicit words (non-targeted)
    'dick'        => 'd**k',
    'dickhead'    => 'd**khead',
    'prick'       => 'pr**k',
    'piss'        => 'p*ss',
    'pissed'      => 'p*ssed',
    'pussy'       => 'p**sy',
    'cum'         => 'c*m',
    'jizz'        => 'j*zz',

    // Softened internet shorthand
    'wtf'         => 'w**',
    'ffs'         => 'f*s',
    'lmfao'       => 'l**ao',
    'lmao'        => 'l**o',

    // Add more SAFE substitutions here as needed.


];
