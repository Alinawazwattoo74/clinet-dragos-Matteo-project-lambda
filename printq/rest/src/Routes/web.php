<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

$router->post('rest', 'RestController@create');
// $router->get('rest/{name}', 'RestController@get');

$router->post('preview', 'RestPreviewController@create');
$router->get('preview/{name}', 'RestPreviewController@get');

$router->post('readPdf', 'RestReadPdfController@create');
$router->post('previewformular', 'RestPreviewformularController@create');
$router->post('oiepreview', 'RestOieController@oiepreviewAction');
$router->get('previewbig/{id}', 'RestPreviewController@getBigAction');
$router->post('previewbrochure', 'RestPreviewBrochureController@create');
$router->post('creatediecut', 'RestPreviewController@creatediecutAction');
$router->post('rearrange', 'RestRearrangeController@create');
// $router->post('previewwhiteunderprintx1', 'RestReadPdfController@create');