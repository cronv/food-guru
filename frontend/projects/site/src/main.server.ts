import { BootstrapContext, bootstrapApplication } from '@angular/platform-browser';

import { AppComponent } from './app/app.component';
import { config } from './app/app.config.server';

/**
 * Контекст обязателен. С Angular 18.2.14 серверная платформа не регистрируется
 * глобально (иначе параллельные запросы делили бы её между собой), и рендер
 * передаёт её только через этот аргумент. Без него bootstrapApplication молча
 * поднимает браузерную платформу, и первый же inject(DOCUMENT) падает
 * с «document is not defined».
 */
const bootstrap = (context: BootstrapContext) => bootstrapApplication(AppComponent, config, context);

export default bootstrap;
