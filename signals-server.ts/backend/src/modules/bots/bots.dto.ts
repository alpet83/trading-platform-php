export interface CreateBotDTO {
  bot_name: string;
  account_id: number;
  config: {
    exchange: string;
    trade_enabled: string;
    position_coef: string;
    monitor_enabled: string;
    min_order_cost: string;
    max_order_cost: string;
    max_limit_distance: string;
    signals_setup: string;
    report_color: string;
    debug_pair: string;
    api_key_name?: string;
    api_secret_name?: string;
    api_secret_sep?: string;
    api_secret_sep_?: string;
    max_pos_cost?: string;
    max_pos_amount?: string;
    shorts_mult?: string;
    last_nonce?: string;
    limit_base_ttl?: string;
    order_timeout?: string;
  };
}
export interface UpdateBotDTO {
  applicant: string;
  config: {
    exchange: string;
    trade_enabled: string;
    position_coef: string;
    monitor_enabled: string;
    min_order_cost: string;
    max_order_cost: string;
    max_limit_distance: string;
    signals_setup: string;
    report_color: string;
    debug_pair: string;
  };
}
