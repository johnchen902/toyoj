<?php
namespace Toyoj;

function getConfig() {
    return [
        "displayErrorDetails" => true,
        "timezone" => "Asia/Taipei",
        "twig" => [
            'strict_variables' => true,
            // 'cache' => '/tmp/toyoj/twig-cache',
            'auto_reload' => true,
        ],
        "dsn" => "pgsql:dbname=toyoj user=toyojweb",
        "auraDBType" => "pgsql",
        "stylesheets" => [
            "Default Style" => "/default.css",
            "TIOJ INFOR Online Judge" => "/tioj.css",
        ],
    ];
}
