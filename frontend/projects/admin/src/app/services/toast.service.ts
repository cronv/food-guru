import { Injectable, signal } from '@angular/core';

export interface ToastMessage {
  id: number;
  text: string;
  kind: 'info' | 'error';
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  readonly messages = signal<ToastMessage[]>([]);
  private counter = 0;

  show(text: string, kind: 'info' | 'error' = 'info'): void {
    const id = ++this.counter;
    this.messages.update((list) => [...list, { id, text, kind }]);
    setTimeout(() => this.dismiss(id), 3200);
  }

  error(text: string): void {
    this.show(text, 'error');
  }

  dismiss(id: number): void {
    this.messages.update((list) => list.filter((m) => m.id !== id));
  }
}
