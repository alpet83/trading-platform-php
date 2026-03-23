export class SignalToggleRequestDto {
  flag: string;
  setup: string | number;
}
export class SignalEditRequestDto {
  field: string;
  value: string | number;
  text?: string;
  setup: string | number;
}
export class SignalDeleteRequestDto {
  id: string;
  setup: string | number;
}

export interface SigEditParams {
  setup?: number;
  format?: 'json' | 'html';
  filter?: string;
  sort?: string;
  order?: 'asc' | 'desc';
}
