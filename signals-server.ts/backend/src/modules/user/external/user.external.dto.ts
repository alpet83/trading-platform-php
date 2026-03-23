export class CreateUserDTO {
  id: string;
  user_name: string;
  enabled: any;
  rights: string[];
}
export class UpdateUserDTO {
  id: string;
  user_name: string;
  rights: string[];
  enabled: any;
}
