<?php
/**
 * CSqliteSchema class file.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link https://www.yiiframework.com/
 * @copyright 2008-2013 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

/**
 * CSqliteSchema is the class for retrieving metadata information from a SQLite (2/3) database.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @package system.db.schema.sqlite
 * @since 1.0
 */
class CSqliteSchema extends CDbSchema
{
	/**
	 * @var array the abstract column types mapped to physical column types.
	 * @since 1.1.6
	 */
	public $columnTypes=array(
		'pk' => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
		'bigpk' => 'integer PRIMARY KEY AUTOINCREMENT NOT NULL',
		'string' => 'varchar(255)',
		'text' => 'text',
		'integer' => 'integer',
		'bigint' => 'integer',
		'float' => 'float',
		'decimal' => 'decimal',
		'datetime' => 'datetime',
		'timestamp' => 'timestamp',
		'time' => 'time',
		'date' => 'date',
		'binary' => 'blob',
		'boolean' => 'tinyint(1)',
		'money' => 'decimal(19,4)',
	);

	/**
	 * Resets the sequence value of a table's primary key.
	 * The sequence will be reset such that the primary key of the next new row inserted
	 * will have the specified value or max value of a primary key plus one (i.e. sequence trimming).
	 * @param CDbTableSchema $table the table schema whose primary key sequence will be reset
	 * @param integer|null $value the value for the primary key of the next new row inserted.
	 * If this is not set, the next new row's primary key will have the max value of a primary
	 * key plus one (i.e. sequence trimming).
	 * @since 1.1
	 */
	public function resetSequence($table,$value=null)
	{
		if($table->sequenceName===null)
			return;
		if($value!==null)
			$value=(int)($value)-1;
		else
			$value=(int)$this->getDbConnection()
				->createCommand("SELECT MAX(`{$table->primaryKey}`) FROM {$table->rawName}")
				->queryScalar();
		try
		{
			// it's possible that 'sqlite_sequence' does not exist
			$this->getDbConnection()
				->createCommand("UPDATE sqlite_sequence SET seq='$value' WHERE name='{$table->name}'")
				->execute();
		}
		catch(Exception $e)
		{
		}
	}

	/**
	 * Enables or disables integrity check. Note that this method used to do nothing before 1.1.14. Since 1.1.14
	 * it changes integrity check state as expected.
	 * @param boolean $check whether to turn on or off the integrity check.
	 * @param string $schema the schema of the tables. Defaults to empty string, meaning the current or default schema.
	 * @since 1.1
	 */
	public function checkIntegrity($check=true,$schema='')
	{
		$this->getDbConnection()->createCommand('PRAGMA foreign_keys='.(int)$check)->execute();
	}

	/**
	 * Returns all table names in the database.
	 * @param string $schema the schema of the tables. This is not used for sqlite database.
	 * @return array all table names in the database.
	 */
	protected function findTableNames($schema='')
	{
		$sql="SELECT DISTINCT tbl_name FROM sqlite_master WHERE tbl_name<>'sqlite_sequence'";
		return $this->getDbConnection()->createCommand($sql)->queryColumn();
	}

	/**
	 * Creates a command builder for the database.
	 * @return CSqliteCommandBuilder command builder instance
	 */
	protected function createCommandBuilder()
	{
		return new CSqliteCommandBuilder($this);
	}

	/**
	 * Loads the metadata for the specified table.
	 * @param string $name table name
	 * @return CDbTableSchema driver dependent table metadata. Null if the table does not exist.
	 */
	protected function loadTable($name)
	{
		$table=new CDbTableSchema;
		$table->name=$name;
		$table->rawName=$this->quoteTableName($name);

		if($this->findColumns($table))
		{
			$this->findConstraints($table);
			return $table;
		}
		else
			return null;
	}

	/**
	 * Collects the table column metadata.
	 * @param CDbTableSchema $table the table metadata
	 * @return boolean whether the table exists in the database
	 */
	protected function findColumns($table)
	{
		$sql="PRAGMA table_info({$table->rawName})";
		$columns=$this->getDbConnection()->createCommand($sql)->queryAll();
		if(empty($columns))
			return false;

		foreach($columns as $column)
		{
			$c=$this->createColumn($column);
			$table->columns[$c->name]=$c;
			if($c->isPrimaryKey)
			{
				if($table->primaryKey===null)
					$table->primaryKey=$c->name;
				elseif(is_string($table->primaryKey))
					$table->primaryKey=array($table->primaryKey,$c->name);
				else
					$table->primaryKey[]=$c->name;
			}
		}
		if(is_string($table->primaryKey) && !strncasecmp($table->columns[$table->primaryKey]->dbType,'int',3))
		{
			$table->sequenceName='';
			$table->columns[$table->primaryKey]->autoIncrement=true;
		}

		return true;
	}

	/**
	 * Collects the foreign key column details for the given table.
	 * @param CDbTableSchema $table the table metadata
	 */
	protected function findConstraints($table)
	{
		$foreignKeys=array();
		$sql="PRAGMA foreign_key_list({$table->rawName})";
		$keys=$this->getDbConnection()->createCommand($sql)->queryAll();
		foreach($keys as $key)
		{
			$column=$table->columns[$key['from']];
			$column->isForeignKey=true;
			$foreignKeys[$key['from']]=array($key['table'],$key['to']);
		}
		$table->foreignKeys=$foreignKeys;
	}

	/**
	 * Creates a table column.
	 * @param array $column column metadata
	 * @return CDbColumnSchema normalized column metadata
	 */
	protected function createColumn($column)
	{
		$c=new CSqliteColumnSchema;
		$c->name=$column['name'];
		$c->rawName=$this->quoteColumnName($c->name);
		$c->allowNull=!$column['notnull'];
		$c->isPrimaryKey=$column['pk']!=0;
		$c->isForeignKey=false;
		$c->comment=null; // SQLite does not support column comments at all

		$c->init(strtolower($column['type']),$column['dflt_value']);
		return $c;
	}

	/**
	 * Builds a SQL statement for renaming a DB table.
	 * @param string $table the table to be renamed. The name will be properly quoted by the method.
	 * @param string $newName the new table name. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB table.
	 * @since 1.1.13
	 */
	public function renameTable($table, $newName)
	{
		return 'ALTER TABLE ' . $this->quoteTableName($table) . ' RENAME TO ' . $this->quoteTableName($newName);
	}

	/**
	 * Builds a SQL statement for truncating a DB table.
	 * @param string $table the table to be truncated. The name will be properly quoted by the method.
	 * @return string the SQL statement for truncating a DB table.
	 * @since 1.1.6
	 */
	public function truncateTable($table)
	{
		return "DELETE FROM ".$this->quoteTableName($table);
	}

	/**
	 * Builds a SQL statement for dropping a DB column.
	 * SQLite has supported `ALTER TABLE ... DROP COLUMN` natively since version 3.35.0
	 * (2021-03-12); on an older SQLite library this statement itself will fail at
	 * execution time with a syntax error, since there is no reliable, cheap way to
	 * detect the linked SQLite version from here.
	 * @param string $table the table whose column is to be dropped. The name will be properly quoted by the method.
	 * @param string $column the name of the column to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping a DB column.
	 * @since 1.1.6
	 */
	public function dropColumn($table, $column)
	{
		return 'ALTER TABLE '.$this->quoteTableName($table)
			.' DROP COLUMN '.$this->quoteColumnName($column);
	}

	/**
	 * Builds a SQL statement for renaming a column.
	 * SQLite has supported `ALTER TABLE ... RENAME COLUMN` natively since version
	 * 3.25.0 (2018-09-15); on an older SQLite library this statement itself will fail
	 * at execution time with a syntax error, since there is no reliable, cheap way to
	 * detect the linked SQLite version from here.
	 * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
	 * @param string $name the old name of the column. The name will be properly quoted by the method.
	 * @param string $newName the new name of the column. The name will be properly quoted by the method.
	 * @return string the SQL statement for renaming a DB column.
	 * @since 1.1.6
	 */
	public function renameColumn($table, $name, $newName)
	{
		return 'ALTER TABLE '.$this->quoteTableName($table)
			.' RENAME COLUMN '.$this->quoteColumnName($name)
			.' TO '.$this->quoteColumnName($newName);
	}

	/**
	 * Builds a SQL statement for adding a foreign key constraint to an existing table.
	 * SQLite has no single-statement way to add a foreign key constraint to an
	 * existing table; the constraint must be baked into the table's definition. This
	 * rebuilds the table (see {@link rebuildTable}) with the new foreign key added,
	 * which is why it returns an array of statements rather than a single one.
	 * @param string $name the name of the foreign key constraint.
	 * @param string $table the table that the foreign key constraint will be added to.
	 * @param string $columns the name of the column to that the constraint will be added on. If there are multiple columns, separate them with commas.
	 * @param string $refTable the table that the foreign key references to.
	 * @param string $refColumns the name of the column that the foreign key references to. If there are multiple columns, separate them with commas.
	 * @param string $delete the ON DELETE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @param string $update the ON UPDATE option. Most DBMS support these options: RESTRICT, CASCADE, NO ACTION, SET DEFAULT, SET NULL
	 * @return array the SQL statements for adding a foreign key constraint to an existing table.
	 * @since 1.1.6
	 */
	public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete=null, $update=null)
	{
		if(is_string($columns))
			$columns=preg_split('/\s*,\s*/',$columns,-1,PREG_SPLIT_NO_EMPTY);
		foreach($columns as $i=>$col)
			$columns[$i]=$this->quoteColumnName($col);
		if(is_string($refColumns))
			$refColumns=preg_split('/\s*,\s*/',$refColumns,-1,PREG_SPLIT_NO_EMPTY);
		foreach($refColumns as $i=>$col)
			$refColumns[$i]=$this->quoteColumnName($col);
		$fk='CONSTRAINT '.$this->quoteColumnName($name)
			.' FOREIGN KEY ('.implode(', ',$columns).')'
			.' REFERENCES '.$this->quoteTableName($refTable)
			.' ('.implode(', ',$refColumns).')';
		if($delete!==null)
			$fk.=' ON DELETE '.$delete;
		if($update!==null)
			$fk.=' ON UPDATE '.$update;
		return $this->rebuildTable($table,function($def) use ($fk)
		{
			$def['constraints'][]=$fk;
			return $def;
		});
	}

	/**
	 * Builds a SQL statement for dropping a foreign key constraint.
	 * SQLite has no single-statement way to drop a foreign key constraint from an
	 * existing table; like {@link addForeignKey}, this rebuilds the table (see
	 * {@link rebuildTable}) without it.
	 * @param string $name the name of the foreign key constraint to be dropped.
	 * @param string $table the table whose foreign is to be dropped. The name will be properly quoted by the method.
	 * @return array the SQL statements for dropping a foreign key constraint.
	 * @since 1.1.6
	 * @throws CDbException if the named constraint is not found on the table
	 */
	public function dropForeignKey($name, $table)
	{
		return $this->rebuildTable($table,function($def) use ($name,$table)
		{
			$found=false;
			foreach($def['constraints'] as $i=>$constraint)
			{
				// The constraint name may or may not be quoted in the table's original
				// CREATE TABLE text (addForeignKey() always quotes its own, but
				// hand-written or externally-authored DDL often does not), so match
				// either form.
				if(preg_match('/^CONSTRAINT\s+(?:"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|(\S+))\s/i',$constraint,$m))
				{
					$constraintName=(isset($m[1]) && $m[1]!=='') ? $m[1] : ((isset($m[2]) && $m[2]!=='') ? $m[2] : ((isset($m[3]) && $m[3]!=='') ? $m[3] : $m[4]));
					if(strcasecmp($constraintName,$name)===0)
					{
						unset($def['constraints'][$i]);
						$found=true;
						break;
					}
				}
			}
			if(!$found)
				throw new CDbException(Yii::t('yii','Foreign key "{name}" on table "{table}" is not found.',array('{name}'=>$name,'{table}'=>$table)));
			return $def;
		});
	}

	/**
	 * Builds a SQL statement for changing the definition of a column.
	 * SQLite has no ALTER TABLE ... ALTER COLUMN; this rebuilds the table (see
	 * {@link rebuildTable}) with the column's type changed, which is why it returns
	 * an array of statements rather than a single one.
	 * @param string $table the table whose column is to be changed. The table name will be properly quoted by the method.
	 * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
	 * @param string $type the new column type. The {@link getColumnType} method will be invoked to convert abstract column type (if any)
	 * into the physical one. Anything that is not recognized as abstract type will be kept in the generated SQL.
	 * For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become 'varchar(255) not null'.
	 * @return array the SQL statements for changing the definition of a column.
	 * @since 1.1.6
	 */
	public function alterColumn($table, $column, $type)
	{
		return $this->rebuildTable($table,function($def) use ($column,$type)
		{
			if(!isset($def['columns'][$column]))
				throw new CDbException(Yii::t('yii','Column "{column}" on table "{table}" is not found.',array('{column}'=>$column,'{table}'=>'')));
			$def['columns'][$column]=$this->quoteColumnName($column).' '.$this->getColumnType($type);
			return $def;
		});
	}

	/**
	 * Builds a SQL statement for dropping an index.
	 * @param string $name the name of the index to be dropped. The name will be properly quoted by the method.
	 * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
	 * @return string the SQL statement for dropping an index.
	 * @since 1.1.6
	 */
	public function dropIndex($name, $table)
	{
		return 'DROP INDEX '.$this->quoteTableName($name);
	}

	/**
	 * Builds a SQL statement for adding a primary key constraint to an existing table.
	 * SQLite has no single-statement way to add a primary key to an existing table;
	 * this rebuilds the table (see {@link rebuildTable}) with the given columns
	 * declared as its primary key, which is why it returns an array of statements
	 * rather than a single one.
	 * @param string $name the name of the primary key constraint. Not used: SQLite
	 * does not name primary key constraints, the column(s) simply are the primary key.
	 * @param string $table the table that the primary key constraint will be added to.
	 * @param string|array $columns comma separated string or array of columns that the primary key will consist of.
	 * @return array the SQL statements for adding a primary key constraint to an existing table.
	 * @since 1.1.13
	 * @throws CDbException if the table already has a primary key
	 */
	public function addPrimaryKey($name,$table,$columns)
	{
		if(!is_array($columns))
			$columns=preg_split('/\s*,\s*/',$columns,-1,PREG_SPLIT_NO_EMPTY);
		return $this->rebuildTable($table,function($def) use ($table,$columns)
		{
			if($def['primaryKey']!==array())
				throw new CDbException(Yii::t('yii','Table "{table}" already has a primary key.',array('{table}'=>$table)));
			$def['primaryKey']=$columns;
			return $def;
		});
	}


	/**
	 * Builds a SQL statement for removing a primary key constraint to an existing table.
	 * SQLite has no single-statement way to drop a primary key from an existing
	 * table; like {@link addPrimaryKey}, this rebuilds the table (see
	 * {@link rebuildTable}) without it. The affected column(s) keep their other
	 * properties (type, nullability, etc.), they just stop being the primary key.
	 * @param string $name the name of the primary key constraint to be removed. Not used, see {@link addPrimaryKey}.
	 * @param string $table the table that the primary key constraint will be removed from.
	 * @return array the SQL statements for removing a primary key constraint from an existing table.
	 * @since 1.1.13
	 * @throws CDbException if the table has no primary key
	 */
	public function dropPrimaryKey($name,$table)
	{
		return $this->rebuildTable($table,function($def) use ($table)
		{
			if($def['primaryKey']===array())
				throw new CDbException(Yii::t('yii','Table "{table}" does not have a primary key.',array('{table}'=>$table)));
			$def['primaryKey']=array();
			return $def;
		});
	}

	/**
	 * Rebuilds a table with a change applied to its definition.
	 *
	 * SQLite does not support most forms of `ALTER TABLE` beyond renaming the table
	 * itself or a column, or adding a column, or (since SQLite 3.35.0) dropping a
	 * column. Every other kind of schema change -- changing a column's type,
	 * adding/dropping a foreign key or a primary key -- requires recreating the
	 * table under SQLite's own documented 12-step procedure:
	 * {@link https://www.sqlite.org/lang_altertable.html#otheralter}. This helper
	 * implements that procedure generically: it reads the table's current
	 * definition, lets the caller transform it, then returns the SQL statements
	 * (create the replacement, copy the data across, drop the original, rename the
	 * replacement into place, recreate any indexes) to actually do so. It is the
	 * caller's responsibility to execute all of them, in order, inside the same
	 * transaction -- {@link CDbCommand} does this automatically whenever a schema
	 * method returns an array instead of a string.
	 *
	 * Known limitation: does not recreate triggers defined on the table, and does
	 * not update foreign keys *from other tables* that reference this one (SQLite
	 * does not enforce referential integrity across a table rebuild by default,
	 * see `PRAGMA foreign_keys`, but does not rewrite the child tables' constraint
	 * definitions either, so they will still reference the correct table name --
	 * only a rename or drop of the referenced column(s) themselves would break
	 * them, and this helper does not support that for a referenced table).
	 *
	 * @param string $table the table to rebuild. The name will be properly quoted by the method.
	 * @param callable $transform receives the table's current definition as an array
	 * with keys 'columns' (column name => quoted column name plus type/constraints,
	 * in original column order), 'primaryKey' (array of column names, possibly
	 * empty) and 'constraints' (array of table-level constraint clauses, e.g.
	 * foreign keys), and must return the (possibly modified) same shape.
	 * @return array the SQL statements to rebuild the table as transformed.
	 * @since 1.1.33
	 */
	protected function rebuildTable($table,$transform)
	{
		$schema=$this->getTable($table,true);
		if($schema===null)
			throw new CDbException(Yii::t('yii','Table "{table}" does not exist.',array('{table}'=>$table)));

		// Column and table-level-constraint clauses are taken from the table's own
		// original CREATE TABLE text (not reconstructed from PRAGMA metadata): a
		// constraint's own name (e.g. a named FOREIGN KEY, which dropForeignKey()
		// needs to find again later) is only available there -- PRAGMA
		// foreign_key_list, for one, does not expose it at all.
		$createSql=$this->getDbConnection()->createCommand(
			'SELECT sql FROM sqlite_master WHERE type=\'table\' AND name='.$this->getDbConnection()->quoteValue($table)
		)->queryScalar();
		$body=substr($createSql,strpos($createSql,'(')+1);
		$body=substr($body,0,strrpos($body,')'));

		$columns=array();
		$constraints=array();
		foreach($this->splitDefinitionClauses($body) as $clause)
		{
			if(preg_match('/^(CONSTRAINT|PRIMARY\s+KEY|UNIQUE|CHECK|FOREIGN\s+KEY)\b/i',$clause))
			{
				// A table-level PRIMARY KEY clause is re-derived from $schema->primaryKey
				// below instead (which already correctly handles both the single- and
				// composite-column cases), so it must not also be kept verbatim here --
				// otherwise a plain alterColumn() etc. would duplicate it.
				if(!preg_match('/^PRIMARY\s+KEY\b/i',$clause))
					$constraints[]=$clause;
			}
			elseif(preg_match('/^\s*(?:"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|(\S+))/',$clause,$m))
			{
				$colName=(isset($m[1]) && $m[1]!=='') ? $m[1] : ((isset($m[2]) && $m[2]!=='') ? $m[2] : ((isset($m[3]) && $m[3]!=='') ? $m[3] : $m[4]));
				$columns[$colName]=trim($clause);
			}
		}

		$primaryKey=$schema->primaryKey===null ? array() : (array)$schema->primaryKey;
		// A single-column primary key declared inline on the column itself (e.g.
		// "id INTEGER PRIMARY KEY" or "... PRIMARY KEY AUTOINCREMENT") is already
		// expressed as part of that column's own clause text, so it must not also
		// be repeated as a table-level constraint below. This is judged from the
		// clause text itself, not CDbColumnSchema::autoIncrement -- that flag
		// reflects SQLite's rowid-alias semantics, which can be true for a column
		// whose primary key is in fact a *separate* table-level constraint (e.g.
		// one this same method added on a previous rebuild), where the column's
		// own clause carries no "PRIMARY KEY" text at all.
		$inlinePk=count($primaryKey)===1 && isset($columns[$primaryKey[0]])
			&& preg_match('/\bPRIMARY\s+KEY\b/i',$columns[$primaryKey[0]]);

		$def=call_user_func($transform,array(
			'columns'=>$columns,
			'primaryKey'=>$inlinePk ? array() : $primaryKey,
			'constraints'=>$constraints,
		));

		$parts=array_values($def['columns']);
		if($def['primaryKey']!==array())
		{
			$quoted=array_map(array($this,'quoteColumnName'),$def['primaryKey']);
			$parts[]='PRIMARY KEY ('.implode(', ',$quoted).')';
		}
		foreach($def['constraints'] as $constraint)
			$parts[]=$constraint;

		$tempTable=$table.'__rebuild_'.substr(md5(uniqid('',true)),0,8);
		$quotedTemp=$this->quoteTableName($tempTable);
		$quotedColumns=implode(', ',array_map(array($this,'quoteColumnName'),array_keys($def['columns'])));

		$statements=array();
		$statements[]='CREATE TABLE '.$quotedTemp." (\n\t".implode(",\n\t",$parts)."\n)";
		$statements[]='INSERT INTO '.$quotedTemp.' ('.$quotedColumns.') SELECT '.$quotedColumns.' FROM '.$schema->rawName;
		$statements[]='DROP TABLE '.$schema->rawName;
		$statements[]=$this->renameTable($tempTable,$table);

		$indexes=$this->getDbConnection()->createCommand(
			'SELECT sql FROM sqlite_master WHERE type=\'index\' AND tbl_name='.$this->getDbConnection()->quoteValue($table).' AND sql IS NOT NULL'
		)->queryColumn();
		foreach($indexes as $indexSql)
			$statements[]=$indexSql;

		return $statements;
	}

	/**
	 * Splits the body of a `CREATE TABLE (...)` statement into its individual
	 * column and table-constraint clauses, respecting parenthesis nesting (e.g. a
	 * `DECIMAL(10,2)` column type's own comma) so a naive `explode(',', ...)` would
	 * not misparse it.
	 *
	 * Known limitation: does not account for a comma inside a quoted string literal
	 * (e.g. a `DEFAULT 'a,b'`), which would be misparsed as two clauses. Reasonable
	 * for now: none of the practical DDL this is meant to support requires that.
	 *
	 * @param string $body the text strictly between a CREATE TABLE statement's outer parentheses.
	 * @return array the individual clauses, each trimmed of surrounding whitespace.
	 * @since 1.1.33
	 */
	private function splitDefinitionClauses($body)
	{
		$clauses=array();
		$depth=0;
		$current='';
		for($i=0,$len=strlen($body);$i<$len;$i++)
		{
			$ch=$body[$i];
			if($ch==='(')
				$depth++;
			elseif($ch===')')
				$depth--;
			if($ch===',' && $depth===0)
			{
				$clauses[]=trim($current);
				$current='';
			}
			else
				$current.=$ch;
		}
		if(trim($current)!=='')
			$clauses[]=trim($current);
		return $clauses;
	}
}
