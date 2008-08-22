<?php
function dump_csv($row) {
	foreach ($row as $key => $val) {
		if (preg_match("~[\"\n,]~", $val)) {
			$row[$key] = '"' . str_replace('"', '""', $val) . '"';
		}
	}
	echo implode(",", $row) . "\n";
}

function dump_table($table, $style, $is_view = false) {
	global $mysql, $max_packet, $types;
	if ($_POST["format"] == "csv") {
		echo "\xef\xbb\xbf";
		if ($style) {
			dump_csv(array_keys(fields($table)));
		}
	} elseif ($style) {
		$result = $mysql->query("SHOW CREATE TABLE " . idf_escape($table));
		if ($result) {
			if ($style == "DROP, CREATE") {
				echo "DROP " . ($is_view ? "VIEW" : "TABLE") . " " . idf_escape($table) . ";\n";
			}
			$create = $mysql->result($result, 1);
			$result->free();
			echo ($style != "CREATE, ALTER" ? $create : ($is_view ? substr_replace($create, " OR REPLACE", 6, 0) : substr_replace($create, " IF NOT EXISTS", 12, 0))) . ";\n\n";
			if ($max_packet < 1073741824) { // protocol limit
				$row_size = 21 + strlen(idf_escape($table));
				foreach (fields($table) as $field) {
					$type = $types[$field["type"]];
					$row_size += 5 + ($field["length"] ? $field["length"] : $type) * (preg_match('~char|text|enum~', $field["type"]) ? 3 : 1); // UTF-8 in MySQL uses up to 3 bytes
				}
				if ($row_size > $max_packet) {
					$max_packet = 1024 * ceil($row_size / 1024);
					echo "SET max_allowed_packet = $max_packet, GLOBAL max_allowed_packet = $max_packet;\n";
				}
			}
		}
		if ($mysql->server_info >= 5) {
			if ($style == "CREATE, ALTER" && !$is_view) {
				$query = "SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, COLLATION_NAME, COLUMN_TYPE, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $mysql->escape_string($table) . "' ORDER BY ORDINAL_POSITION";
?>
DELIMITER ;;
CREATE PROCEDURE phpminadmin_alter () BEGIN
	DECLARE _column_name, _collation_name, _column_type, after varchar(64) DEFAULT '';
	DECLARE _column_default longtext;
	DECLARE _is_nullable char(3);
	DECLARE _extra varchar(20);
	DECLARE _column_comment varchar(255);
	DECLARE done, set_after bool DEFAULT 0;
	DECLARE add_columns text DEFAULT '<?php
$fields = array();
$result = $mysql->query($query);
$after = "";
while ($row = $result->fetch_assoc()) {
	$row["default"] = (isset($row["COLUMN_DEFAULT"]) ? "'" . $mysql->escape_string($row["COLUMN_DEFAULT"]) . "'" : "NULL");
	$row["after"] = $mysql->escape_string($after); //! rgt AFTER lft, lft AFTER id doesn't work
	$row["alter"] = $mysql->escape_string(idf_escape($row["COLUMN_NAME"])
		. " $row[COLUMN_TYPE]"
		. ($row["COLLATION_NAME"] ? " COLLATE $row[COLLATION_NAME]" : "")
		. (isset($row["COLUMN_DEFAULT"]) ? " DEFAULT $row[default]" : "")
		. ($row["IS_NULLABLE"] == "YES" ? "" : " NOT NULL")
		. ($row["EXTRA"] ? " $row[EXTRA]" : "")
		. ($row["COLUMN_COMMENT"] ? " COMMENT '" . $mysql->escape_string($row["COLUMN_COMMENT"]) . "'" : "")
		. ($after ? " AFTER " . idf_escape($after) : " FIRST")
	);
	echo ", ADD $row[alter]";
	$fields[] = $row;
	$after = $row["COLUMN_NAME"];
}
$result->free();
?>';
	DECLARE columns CURSOR FOR <?php echo $query; ?>;
	DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
	SET @alter_table = '';
	OPEN columns;
	REPEAT
		FETCH columns INTO _column_name, _column_default, _is_nullable, _collation_name, _column_type, _extra, _column_comment;
		IF NOT done THEN
			SET set_after = 1;
			CASE _column_name<?php
foreach ($fields as $row) {
	echo "
				WHEN '" . $mysql->escape_string($row["COLUMN_NAME"]) . "' THEN
					SET add_columns = REPLACE(add_columns, ', ADD $row[alter]', '');
					IF NOT (_column_default <=> $row[default]) OR _is_nullable != '$row[IS_NULLABLE]' OR _collation_name != '$row[COLLATION_NAME]' OR _column_type != '$row[COLUMN_TYPE]' OR _extra != '$row[EXTRA]' OR _column_comment != '" . $mysql->escape_string($row["COLUMN_COMMENT"]) . "' OR after != '$row[after]' THEN
						SET @alter_table = CONCAT(@alter_table, ', MODIFY $row[alter]');
					END IF;"; //! don't replace in comment
}
?>

				ELSE
					SET @alter_table = CONCAT(@alter_table, ', DROP ', _column_name);
					SET set_after = 0;
			END CASE;
			IF set_after THEN
				SET after = _column_name;
			END IF;
		END IF;
	UNTIL done END REPEAT;
	CLOSE columns;
	IF @alter_table != '' OR add_columns != '' THEN
		SET @alter_table = CONCAT('ALTER TABLE <?php echo idf_escape($table); ?>', SUBSTR(CONCAT(add_columns, @alter_table), 2));
		PREPARE alter_command FROM @alter_table;
		EXECUTE alter_command;
		DROP PREPARE alter_command;
	END IF;
END;;
DELIMITER ;
CALL phpminadmin_alter;
DROP PROCEDURE phpminadmin_alter;

<?php
				//! indexes
			}
			
			$result = $mysql->query("SHOW TRIGGERS LIKE '" . $mysql->escape_string(addcslashes($table, "%_")) . "'");
			if ($result->num_rows) {
				echo "DELIMITER ;;\n\n";
				while ($row = $result->fetch_assoc()) {
					echo "CREATE TRIGGER " . idf_escape($row["Trigger"]) . " $row[Timing] $row[Event] ON " . idf_escape($row["Table"]) . " FOR EACH ROW $row[Statement];;\n\n";
				}
				echo "DELIMITER ;\n\n";
			}
			$result->free();
		}
	}
}

function dump_data($table, $style, $from = "") {
	global $mysql, $max_packet;
	if ($style) {
		if ($_POST["format"] != "csv" && $style == "TRUNCATE, INSERT") {
			echo "TRUNCATE " . idf_escape($table) . ";\n";
		}
		$result = $mysql->query("SELECT * " . ($from ? $from : "FROM " . idf_escape($table))); //! enum and set as numbers, binary as _binary, microtime
		if ($result) {
			$insert = "INSERT INTO " . idf_escape($table) . " VALUES ";
			$length = 0;
			while ($row = $result->fetch_assoc()) {
				if ($_POST["format"] == "csv") {
					dump_csv($row);
				} elseif ($style == "UPDATE") {
					$set = array();
					foreach ($row as $key => $val) {
						$row[$key] = (isset($val) ? "'" . $mysql->escape_string($val) . "'" : "NULL");
						$set[] = idf_escape($key) . " = " . (isset($val) ? "'" . $mysql->escape_string($val) . "'" : "NULL");
					}
					echo "INSERT INTO " . idf_escape($table) . " (" . implode(", ", array_map('idf_escape', array_keys($row))) . ") VALUES (" . implode(", ", $row) . ") ON DUPLICATE KEY UPDATE " . implode(", ", $set) . ";\n";
				} else {
					foreach ($row as $key => $val) {
						$row[$key] = (isset($val) ? "'" . $mysql->escape_string($val) . "'" : "NULL");
					}
					$s = "(" . implode(", ", $row) . ")";
					if (!$length) {
						echo $insert, $s;
						$length = strlen($insert) + strlen($s);
					} else {
						$length += 2 + strlen($s);
						if ($length < $max_packet) {
							echo ", ", $s;
						} else {
							echo ";\n", $insert, $s;
							$length = strlen($insert) + strlen($s);
						}
					}
				}
			}
			if ($_POST["format"] != "csv" && $style != "UPDATE" && $result->num_rows) {
				echo ";\n";
			}
			$result->free();
		}
		if ($_POST["format"] != "csv") {
			echo "\n";
		}
	}
}

function dump_headers($identifier, $multi_table = false) {
	$filename = (strlen($identifier) ? preg_replace('~[^a-z0-9_]~i', '-', $identifier) : "dump");
	$ext = ($_POST["format"] == "sql" ? "sql" : ($multi_table ? "tar" : "csv"));
	header("Content-Type: " . ($ext == "tar" ? "application/x-tar" : ($ext == "sql" || $_POST["output"] != "file" ? "text/plain" : "text/csv")) . "; charset=utf-8");
	header("Content-Disposition: " . ($_POST["output"] == "file" ? "attachment" : "inline") . "; filename=$filename.$ext");
	return $ext;
}

$dump_options = lang('Output') . ": <select name='output'><option value='text'>" . lang('open') . "</option><option value='file'>" . lang('save') . "</option></select> " . lang('Format') . ": <select name='format'><option value='sql'>" . lang('SQL') . "</option><option value='csv'>" . lang('CSV') . "</option></select>";
$max_packet = 0;