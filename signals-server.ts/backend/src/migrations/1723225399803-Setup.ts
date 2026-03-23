import {
  MigrationInterface,
  QueryRunner,
  Table,
  TableForeignKey,
} from 'typeorm';

export class Setup1723225399803 implements MigrationInterface {
  public async up(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.createTable(
      new Table({
        name: 'setup',
        columns: [
          {
            name: 'id',
            type: 'int',
            isPrimary: true,
            isGenerated: true,
            generationStrategy: 'increment',
          },
          {
            name: 'title',
            type: 'varchar',
            isNullable: false,
          },
          {
            name: 'createdAt',
            type: 'timestamp',
            default: 'CURRENT_TIMESTAMP',
          },
        ],
      }),
      true,
    );

    await queryRunner.createTable(
      new Table({
        name: 'user_setup',
        columns: [
          {
            name: 'setupId',
            type: 'int',
            isPrimary: true,
          },
          {
            name: 'userId',
            type: 'int',
            isPrimary: true,
          },
        ],
      }),
      true,
    );

    // --- Добавляем внешние ключи ---
    await queryRunner.createForeignKeys('user_setup', [
      new TableForeignKey({
        columnNames: ['setupId'],
        referencedColumnNames: ['id'],
        referencedTableName: 'setup',
        onDelete: 'CASCADE',
      }),
      new TableForeignKey({
        columnNames: ['userId'],
        referencedColumnNames: ['id'],
        referencedTableName: 'user',
        onDelete: 'CASCADE',
      }),
    ]);
  }

  public async down(queryRunner: QueryRunner): Promise<void> {
    // Удаляем внешние ключи
    const table = await queryRunner.getTable('user_setup');
    const foreignKeys = table?.foreignKeys ?? [];
    for (const fk of foreignKeys) {
      await queryRunner.dropForeignKey('user_setup', fk);
    }

    // Удаляем таблицы
    await queryRunner.dropTable('user_setup');
    await queryRunner.dropTable('setup');
  }
}
