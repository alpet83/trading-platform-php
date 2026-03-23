import { Injectable } from '@nestjs/common';
import { InjectEntityManager } from '@nestjs/typeorm';
import { EntityManager } from 'typeorm';
import { TelegramSessionEntity } from './telegram.session.entity';
import { AsyncSessionStore } from 'telegraf/session';

@Injectable()
export class PostgresStore<Session = any>
  implements AsyncSessionStore<Session>
{
  constructor(
    @InjectEntityManager()
    private readonly man: EntityManager,
  ) {}

  async get(name: string): Promise<Session | undefined> {
    const result = await this.man.findOne(TelegramSessionEntity, {
      where: {
        key: name,
      },
    });
    return result ? (result.session as Session) : undefined;
  }

  async set(name: string, value: Session): Promise<unknown> {
    return await this.man.upsert(
      TelegramSessionEntity,
      {
        key: name,
        session: value as object,
      },
      {
        conflictPaths: ['key'],
      },
    );
  }

  async delete(name: string): Promise<void> {
    await this.man.delete(TelegramSessionEntity, { key: name });
  }
}
