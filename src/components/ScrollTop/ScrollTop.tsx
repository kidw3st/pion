'use client';

import { useEffect, useState } from 'react';
import styles from './ScrollTop.module.css';

/**
 * "Back to top" control. The catalogue runs to several screens once a category
 * is loaded in full, so this appears after the first screen has scrolled past
 * and sits under the cart button in the same round beige shape the rest of the
 * site's floating controls use.
 */
export function ScrollTop() {
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const onScroll = () => setVisible(window.scrollY > 600);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const toTop = () => {
    // Someone who has asked their system for less motion gets a plain jump.
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
  };

  return (
    <button
      type="button"
      className={visible ? styles.button : styles.buttonHidden}
      onClick={toTop}
      aria-label="Наверх"
      tabIndex={visible ? 0 : -1}
      aria-hidden={visible ? undefined : true}
    >
      {/* The slider's chevron, turned to point up. */}
      <svg viewBox="0 0 33 17.3" width="20" height="11" fill="none" aria-hidden="true">
        <polyline points="0.5,16.5 16.5,0.5 32.5,16.5" stroke="currentColor" strokeWidth="1.5" />
      </svg>
    </button>
  );
}
