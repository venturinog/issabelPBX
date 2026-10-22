<?php
if(!class_exists('issabelpbx_DB_mysqli') && class_exists('DB_mysqli')) {
	class issabelpbx_DB_mysqli extends DB_mysqli
	{
		function insert_id() {
			$result = @mysqli_insert_id($this->connection);
			if (!$result) {
				return $this->mysqliRaiseError();
			} else {
				return $result;
			}
		}
	}
}

// El driver ext/mysql que extiende esta clase se elimino en PHP 7.0 y en
// Issabel 6 db_connect.php siempre usa mysqli, asi que nunca se declara. Se
// conserva como placeholder de interfaz: el error del driver es el
// comportamiento correcto si alguien reviviera mysql://.
if(!class_exists('issabelpbx_DB_mysql') && class_exists('DB_mysql')) {
	class issabelpbx_DB_mysql extends DB_mysql
	{
		function insert_id() {
			return $this->mysqlRaiseError(DB_ERROR_UNSUPPORTED);
		}
	}
}

// La extension ext/sqlite (v1) se elimino en PHP 5.4 y el motor "sqlite" se
// rechaza expresamente en db_connect.php: este placeholder no se declara.
if(!class_exists('issabelpbx_DB_sqlite') && class_exists('DB_sqlite')) {
	class issabelpbx_DB_sqlite extends DB_sqlite
	{
		function insert_id() {
			return $this->sqliteRaiseError(DB_ERROR_UNSUPPORTED);
		}
	}
}
