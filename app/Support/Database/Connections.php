<?php

namespace App\Support\Database;

trait Connections
{
    public function getConnections()
    {
        $connections = config('database.connections');

        $tenants = array_values(array_diff(array_keys($connections), ['sqlite', 'mysql', 'pgsql', 'sqlsrv', 'bussolastaging', 'mariadb', 'audit']));

        // Instalação com um único banco (sem conexões extras por município):
        // processa a conexão padrão em vez de não processar nada.
        if (empty($tenants)) {
            return [config('database.default')];
        }

        return $tenants;
    }
}
