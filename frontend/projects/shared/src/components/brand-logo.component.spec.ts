import { ComponentFixture, TestBed } from '@angular/core/testing';

import { BrandLogoComponent } from './brand-logo.component';

describe('BrandLogoComponent', () => {
  let fixture: ComponentFixture<BrandLogoComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [BrandLogoComponent] }).compileComponents();
    fixture = TestBed.createComponent(BrandLogoComponent);
  });

  it('по умолчанию рисует надпись целиком', () => {
    fixture.detectChanges();

    const svg = fixture.nativeElement.querySelector('svg') as SVGElement;
    expect(svg.getAttribute('viewBox')).toBe('0 -2 1040 142');
  });

  it('буквы наследуют цвет темы, а не зашиты в файл', () => {
    fixture.detectChanges();

    const lettering = fixture.nativeElement.querySelector('g[stroke]') as SVGElement;
    expect(lettering.getAttribute('stroke')).toBe('currentColor');
  });

  it('кольцо и лист остаются фирменными в любой теме', () => {
    fixture.detectChanges();

    const html = fixture.nativeElement.innerHTML as string;
    expect(html).toContain('#E3401F');
    expect(html).toContain('#1E8A57');
  });

  it('вариант «знак» рисует только кольцо с листом', () => {
    fixture.componentRef.setInput('variant', 'mark');
    fixture.detectChanges();

    const svg = fixture.nativeElement.querySelector('svg') as SVGElement;
    expect(svg.getAttribute('viewBox')).toBe('0 0 120 142');
    expect(fixture.nativeElement.querySelector('g[stroke]')).toBeNull();
  });

  it('подпись для скринридера задаётся снаружи', () => {
    fixture.componentRef.setInput('label', '');
    fixture.detectChanges();

    const svg = fixture.nativeElement.querySelector('svg') as SVGElement;
    expect(svg.getAttribute('aria-label')).toBe('');
  });
});
