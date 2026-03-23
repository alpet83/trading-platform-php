import { MigrationInterface, QueryRunner } from 'typeorm';

export class Roles1723224391704 implements MigrationInterface {
  name = 'Roles1723224391704';

  public async up(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.query(`
        CREATE TABLE "role_entity"
        (
            "id"   SERIAL            NOT NULL,
            "name" character varying NOT NULL,
            CONSTRAINT "PK_7bc1bd2364b6e9bf7c84b1e52e2" PRIMARY KEY ("id")
        )
    `);
    await queryRunner.query(`
        CREATE TABLE "user_roles"
        (
            "user_id" integer NOT NULL,
            "role_id" integer NOT NULL,
            CONSTRAINT "PK_23ed6f04fe43066df08379fd034" PRIMARY KEY ("user_id", "role_id")
        )
    `);
    await queryRunner.query(`
        CREATE INDEX "IDX_87b8888186ca9769c960e92687" ON "user_roles" ("user_id")
    `);
    await queryRunner.query(`
        CREATE INDEX "IDX_b23c65e50a758245a33ee35fda" ON "user_roles" ("role_id")
    `);
    await queryRunner.query(`
        ALTER TABLE "user_roles"
            ADD CONSTRAINT "FK_87b8888186ca9769c960e926870" FOREIGN KEY ("user_id") REFERENCES "user_entity" ("id") ON DELETE CASCADE ON UPDATE CASCADE
    `);
    await queryRunner.query(`
        ALTER TABLE "user_roles"
            ADD CONSTRAINT "FK_b23c65e50a758245a33ee35fda1" FOREIGN KEY ("role_id") REFERENCES "role_entity" ("id") ON DELETE CASCADE ON UPDATE CASCADE
    `);
  }

  public async down(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.query(`
        ALTER TABLE "user_roles"
            DROP CONSTRAINT "FK_b23c65e50a758245a33ee35fda1"
    `);
    await queryRunner.query(`
        ALTER TABLE "user_roles"
            DROP CONSTRAINT "FK_87b8888186ca9769c960e926870"
    `);
    await queryRunner.query(`
        DROP INDEX "public"."IDX_b23c65e50a758245a33ee35fda"
    `);
    await queryRunner.query(`
        DROP INDEX "public"."IDX_87b8888186ca9769c960e92687"
    `);
    await queryRunner.query(`
        DROP TABLE "user_roles"
    `);
    await queryRunner.query(`
        DROP TABLE "role_entity"
    `);
  }
}
