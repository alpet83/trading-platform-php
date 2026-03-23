import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity()
export class TelegramSessionEntity {
  @PrimaryColumn()
  key: string;

  @Column({
    type: 'jsonb',
  })
  session: object;
}
