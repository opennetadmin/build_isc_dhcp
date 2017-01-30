<?php


// Basic model.. a plugin needs a controller.php that will be included automatically
// this file should only contain the route maps for the api endpoints
// The route likely will include more code/functions to do the job.  This can be
// done by requiring a file that is named {plugin_name}.php

$app->get('/v1/hosts/{host}/services/iscdhcpconf', function ($request, $response, $args) {

  // Load any supporting functions for this plugin
  require_once(basename(__DIR__).'.php');

  $args['server'] = $args['host'];

  list($status, $dhcp_conf) = build_dhcpd_conf($args + (array)$request->getQueryParams());

  if ($status) {
    $output['status_code'] = 1;
    $output['status_msg'] = 'There was an error building the configuration\n'.$dhcp_conf;
    return $response->withJson($output)->withStatus(400);
  } else {
    $response->getBody()->write($dhcp_conf);
    $response = $response->withHeader('Content-type', 'text/plain');
    return $response;
  }
})->add(new ONA\auth\tokenauth());

