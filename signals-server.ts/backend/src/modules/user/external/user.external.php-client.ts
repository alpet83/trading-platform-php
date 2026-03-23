import fetch from 'node-fetch';
import {
  CreateUserDTO,
  UpdateUserDTO,
} from '@modules/user/external/user.external.dto';

type PhpMutationPayload = Partial<CreateUserDTO & UpdateUserDTO>;

interface PhpMutationOptions {
  endpoint: string;
  actionName: string;
  userId: string;
  payload: PhpMutationPayload;
  onSuccess?: (status: number, result: any) => Promise<void>;
  responseType?: 'json' | 'raw';
}

export class UserExternalPhpClient {
  constructor(
    private readonly baseUrl: string,
    private readonly token: string,
  ) {}

  async callJson(
    endpoint: string,
    method: 'GET' | 'POST',
    userId: string,
    payload?: PhpMutationPayload,
  ) {
    const response = await fetch(this.baseUrl + endpoint, {
      method,
      headers: this.buildHeaders(userId, method === 'POST'),
      body: method === 'POST' ? this.toBody(payload) : undefined,
    });

    return this.parseJsonResponse(response, endpoint);
  }

  async callMutation(options: PhpMutationOptions) {
    const response = await fetch(this.baseUrl + options.endpoint, {
      method: 'POST',
      headers: this.buildHeaders(options.userId, true),
      body: this.toBody(options.payload),
    });

    if (response.status > 399) {
      const errorBody = await response.text();
      throw new Error(
        `API(${options.actionName}) error #${response.status}: ${JSON.stringify(errorBody)}`,
      );
    }

    const result =
      options.responseType === 'raw' ? response : await response.json();

    if (options.onSuccess) {
      await options.onSuccess(response.status, result);
    }

    return result;
  }

  private buildHeaders(userId: string, hasBody: boolean) {
    return {
      ...(hasBody ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
      Authorization: `Bearer ${this.token}`,
      'X-User-Id': userId,
    };
  }

  private toBody(payload?: PhpMutationPayload): string | undefined {
    if (!payload) {
      return undefined;
    }

    const params = new URLSearchParams();

    if (payload.user_name !== undefined && payload.user_name !== '') {
      params.append('user_name', String(payload.user_name));
    }

    if (payload.id !== undefined) {
      params.append('id', String(payload.id));
    }

    if (payload.enabled !== undefined) {
      params.append('enabled', String(payload.enabled));
    }

    if (Array.isArray(payload.rights)) {
      payload.rights.forEach((right) => params.append('rights[]', right));
    }

    return params.toString();
  }

  private async parseJsonResponse(response: any, endpoint: string) {
    try {
      return await response.json();
    } catch {
      const errorBody = await response.text();
      throw new Error(
        `API HTTP error ${response.status} (${endpoint}): ${JSON.stringify(errorBody)}`,
      );
    }
  }
}
