<?php

/*
|--------------------------------------------------------------------------
| Pricing
|--------------------------------------------------------------------------
|
| Settings shared by every product's pricing, as opposed to any one supplier's.
|
*/

return [

    /*
    | The country we sell from.
    |
    | Decides what counts as domestic across every product — which identity document a
    | passenger is asked for, and which pricing rules a flight or a hotel matches. It
    | lives here rather than under a supplier because the answer must be identical for
    | flights and hotels; two suppliers each holding their own copy is two answers
    | waiting to disagree.
    |
    | Falls back to TBOAIR_POINT_OF_SALE, which is where this used to live, so an
    | existing deployment keeps its configured value without an .env change.
    */
    'point_of_sale' => strtoupper(env('POINT_OF_SALE', env('TBOAIR_POINT_OF_SALE', 'PH'))),

];
