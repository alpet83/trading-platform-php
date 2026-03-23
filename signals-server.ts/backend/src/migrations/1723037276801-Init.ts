import { MigrationInterface, QueryRunner } from 'typeorm';

export class Init1723037276801 implements MigrationInterface {
  name = 'Init1723037276801';

  public async up(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.query(`
            CREATE TABLE "telegram_session_entity" (
                "key" character varying NOT NULL,
                "session" jsonb NOT NULL,
                CONSTRAINT "PK_38d9f7e116d970c787baaabb387" PRIMARY KEY ("key")
            )
        `);
    await queryRunner.query(`
            CREATE TABLE "user_entity" (
                "id" SERIAL NOT NULL,
                "username" character varying NOT NULL,
                "hashed_password" character varying NOT NULL,
                "created_at" TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                "updated_at" TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                "deletedAt" TIMESTAMP,
                CONSTRAINT "PK_b54f8ea623b17094db7667d8206" PRIMARY KEY ("id")
            )
        `);
    await queryRunner.query(`
            CREATE TABLE "base_auth_entity" (
                "userId" integer NOT NULL,
                "refreshToken" character varying NOT NULL,
                "created_at" TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT now(),
                CONSTRAINT "PK_afbb274f3a039458ad868436a96" PRIMARY KEY ("refreshToken")
            )
        `);
  }

  public async down(queryRunner: QueryRunner): Promise<void> {
    await queryRunner.query(`
            DROP TABLE "base_auth_entity"
        `);
    await queryRunner.query(`
            DROP TABLE "user_entity"
        `);
    await queryRunner.query(`
            DROP TABLE "telegram_session_entity"
        `);
  }
}
