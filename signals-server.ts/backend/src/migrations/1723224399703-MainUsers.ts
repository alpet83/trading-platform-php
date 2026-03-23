import { MigrationInterface, QueryRunner } from 'typeorm';

export class MainUsers1723224399703 implements MigrationInterface {
  name = 'MainUsers1723224399703';

  public async up(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.query(
      `INSERT INTO "user_entity" ("id", "username", "hashed_password")
       VALUES (1, 'test@test.ru', '$2b$10$Jt5.Lz4zwDIVXusZitalg.R6u0p7BC.s1p2n.0JosJ8GmOVfKorrm')`,
    );
    await queryRunner.query(
      `INSERT INTO "role_entity" ("id", "name") VALUES (1, 'admin')`,
    );
    await queryRunner.query(
      'INSERT INTO "user_roles" ("user_id", "role_id") VALUES (1, 1)',
    );
  }

  public async down(queryRunner: QueryRunner): Promise<void> {}
}
