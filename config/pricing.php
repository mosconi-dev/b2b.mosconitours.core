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

    /*
    | Round the final selling price up to this step — 10, 50, 100 — so a price reads as
    | deliberate rather than computed. Applied ONCE, after every level has contributed:
    | rounding each rung and summing gives a total that does not equal the rounded sum,
    | and a breakdown that visibly fails to add up is worse than the drift.
    |
    | 0 leaves the arithmetic alone, which is the default until the business decides.
    */
    'rounding' => (int) env('PRICING_ROUNDING', 0),

    /*
    | A ceiling on everything the levels added together, as an amount above net.
    |
    | Two individually reasonable levels can stack into something the end customer reads
    | as absurd, and they see only the final number. Null = no ceiling, which is the
    | default until the business sets one.
    */
    'max_total_markup' => env('PRICING_MAX_TOTAL_MARKUP'),

    /*
    | The sample supplier rate the admin hierarchy view prices every agency against, so
    | one screen shows what each partner would pay for a comparable fare.
    */
    'preview_net' => env('PRICING_PREVIEW_NET', 5000),

];
