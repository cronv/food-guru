import { Component, OnInit, PLATFORM_ID, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { isPlatformBrowser } from '@angular/common';

import { AuthService } from '@shared/services/auth.service';
import { HeaderComponent } from './components/header.component';
import { FooterComponent } from './components/footer.component';
import { BottomNavComponent } from './components/bottom-nav.component';
import { ToastComponent } from './components/toast.component';

@Component({
    selector: 'app-root',
    imports: [RouterOutlet, HeaderComponent, FooterComponent, ToastComponent, BottomNavComponent],
    template: `
    <app-header />
    <main id="content">
      <router-outlet />
    </main>
    <app-footer />
    <app-bottom-nav />
    <app-toast />
  `
})
export class AppComponent implements OnInit {
  private auth = inject(AuthService);
  private platformId = inject(PLATFORM_ID);

  ngOnInit(): void {
    // Всё, что трогает браузерное окружение, делаем только в браузере:
    // при SSR ни cookie читателя, ни window недоступны.
    if (!isPlatformBrowser(this.platformId)) return;

    this.auth.restore().subscribe();
  }
}
