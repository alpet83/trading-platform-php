import { IsBoolean, IsNumber, IsString } from 'class-validator';

export class UpdatePositionCoefRequestDto {
  @IsString()
  bot: string;

  @IsNumber()
  position_coef: number;
}
export class UpdateTradeEnabledRequestDto {
  @IsString()
  bot: string;

  @IsBoolean()
  trade_enabled: boolean;
}
export class CancelOrderRequestDto {
  @IsString()
  bot: string;

  @IsString()
  order_id: string;
}
export class UpdateOffsetRequestDto {
  @IsString()
  exchange: string;
  @IsString()
  account: string;
  @IsString()
  pair_id: string;
  @IsNumber()
  offset: number;
}
