export type Signal = {
    id: string
    timestamp: number
    signal_no: string
    side: string
    pair: string
    pair_id: string
    multiplier: string
    accumulated_position: number
    limit_price: string
    take_profit: string
    stop_loss: string
    stop_loss_diff_percent: number
    ttl: string
    flags: {
        limit_price: boolean,
        take_profit: boolean,
        stop_loss: boolean
        stop_endless: boolean
    },
    comment: string
    currency_symbol: string
    last_price: number
    coefficient: number
    amount: number
    colors: {
        background: string,
        font: string
    }
}
export const SignalSort = {
    time: 'timestamp',
    sigID: 'id',
    side: 'side',
    pair: 'pair',
    mult: 'multiplier',
    accumPos: 'accumulated_position',
    limitPrice: '',
    takeProfit: '',
    stopLoss: '',
    slEndless: '',
    lastPrice: '',
    comment: '',
}
export type TableHeaders = {[key: string]: string}
export type SignalData = {
    status: string,
    setup: string,
    timestamp: string,
    data: {
        signals?: Signal[]
        pairs: {
            pair: string,
            pair_id: string,
            signals: Signal[]
        }[]
        summary: {
            total_signals: number,
            buys: {
                [key: string]: number
            },
            shorts: any[],
            symbol_errors: number
        }
    },
    setup_info: {
        setup_id: string,
        btc_pair_id: string,
        eth_pair_id: string,
        processing_time: number
    }
}
export type AddSignalForm = {
    side: 'BUY' | 'SELL',
    pair: string,
    multiplier?: string,
    signal_no: string,
}