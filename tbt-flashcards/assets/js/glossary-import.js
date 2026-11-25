/**
 * TBT Flashcards — Import glossaries screen (wp-admin, 2.4.0).
 *
 * Reads the old wpDataTables glossaries (10 tables per request, one request
 * at a time), shows one row per lesson and table with a preview, then
 * imports the ticked lessons (20 per request) or undoes imports. The server
 * re-reads every table itself; rows are never sent from here. All text is
 * set with textContent.
 *
 * Paste words (2.4.1): one row per lesson still showing a wpDataTables
 * glossary, with its Quizlet/Multidecker links and a box for a Quizlet
 * export. The word count here is a preview; the server parses again.
 */
( function () {
    'use strict';

    var cfg = window.tbtFcImport || {};
    var lessons = Array.isArray( cfg.lessons ) ? cfg.lessons : [];
    var reads = {};      // table ID → read summary
    var checked = {};    // "post:table" → true
    var failedRun = {};  // post → note from a failed import
    var filter = 'all';
    var busy = false;

    var $ = function ( id ) {
        return document.getElementById( id );
    };
    var countsEl = $( 'tbt-fc-imp-counts' );
    var readBtn = $( 'tbt-fc-imp-read' );
    var rescanBtn = $( 'tbt-fc-imp-rescan' );
    var progress = $( 'tbt-fc-imp-progress' );
    var errorBox = $( 'tbt-fc-imp-error' );
    var list = $( 'tbt-fc-imp-list' );
    var tally = $( 'tbt-fc-imp-tally' );
    var tbody = $( 'tbt-fc-imp-rows' );
    var runBtn = $( 'tbt-fc-imp-run' );
    var undoAllBtn = $( 'tbt-fc-imp-undo-all' );
    var copyBtn = $( 'tbt-fc-imp-copy' );
    var confirmBox = $( 'tbt-fc-imp-confirm' );
    var allBox = $( 'tbt-fc-imp-all' );

    var TYPES = { google_spreadsheet: 'Google Sheet', xls: 'Excel file', manual: 'wpDataTables table' };

    function el( tag, cls, text ) {
        var node = document.createElement( tag );
        if ( cls ) {
            node.className = cls;
        }
        if ( undefined !== text && null !== text ) {
            node.textContent = String( text );
        }
        return node;
    }

    function api( path, body ) {
        return window.fetch( cfg.api + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify( body || {} )
        } ).then( function ( res ) {
            return res.json().catch( function () {
                return {};
            } ).then( function ( json ) {
                if ( ! res.ok ) {
                    throw new Error( ( json && json.message ) || 'HTTP ' + res.status );
                }
                return json;
            } );
        } );
    }

    function showError( text ) {
        errorBox.querySelector( 'p' ).textContent = text;
        errorBox.hidden = ! text;
    }

    function setProgress( label, done, total ) {
        progress.hidden = false;
        var bar = progress.querySelector( 'progress' );
        bar.max = Math.max( 1, total );
        bar.value = done;
        progress.querySelector( 'span' ).textContent = label;
    }

    function setBusy( on ) {
        busy = on;
        [ readBtn, rescanBtn, runBtn, undoAllBtn ].forEach( function ( b ) {
            b.disabled = on;
        } );
        render();
    }

    // Run batches one request at a time.
    function batches( items, size, send, label ) {
        var done = 0;
        var chunks = [];
        for ( var i = 0; i < items.length; i += size ) {
            chunks.push( items.slice( i, i + size ) );
        }
        setProgress( label( 0, items.length ), 0, items.length );
        return chunks.reduce( function ( p, chunk ) {
            return p.then( function () {
                return send( chunk ).then( function () {
                    done += chunk.length;
                    setProgress( label( done, items.length ), done, items.length );
                    render();
                } );
            } );
        }, Promise.resolve() );
    }

    /* ---------- Rows ---------- */

    function rows() {
        var out = [];
        lessons.forEach( function ( lesson ) {
            ( lesson.items || [] ).forEach( function ( item ) {
                out.push( tableRow( lesson, item ) );
            } );
            if ( lesson.imported ) {
                out.push( { kind: 'imported', lesson: lesson, key: lesson.post + ':imported', state: 'imported' } );
            }
        } );
        return out;
    }

    function tableRow( lesson, item ) {
        var r = { kind: 'table', lesson: lesson, item: item, key: lesson.post + ':' + item.table, read: reads[ item.table ] || null };
        if ( failedRun[ lesson.post ] ) {
            r.state = 'failed';
            r.note = 'Import failed: ' + failedRun[ lesson.post ];
        } else if ( item.global ) {
            r.state = 'failed';
            r.note = 'Inside a Divi global module (library item ' + item.global + '): fix by hand';
        } else if ( ! r.read ) {
            r.state = 'unread';
        } else if ( r.read.ok ) {
            r.state = 'ready';
        } else {
            r.state = 'failed';
            r.note = r.read.note;
        }
        return r;
    }

    function counts() {
        var tables = {};
        var n = 0;
        lessons.forEach( function ( l ) {
            if ( l.items && l.items.length ) {
                n++;
                l.items.forEach( function ( i ) {
                    tables[ i.table ] = true;
                } );
            }
        } );
        var imported = lessons.filter( function ( l ) {
            return l.imported;
        } ).length;
        countsEl.textContent = '';
        countsEl.appendChild( el( 'strong', '', n + ( 1 === n ? ' lesson' : ' lessons' ) ) );
        countsEl.appendChild( document.createTextNode( ', ' ) );
        countsEl.appendChild( el( 'strong', '', Object.keys( tables ).length + ( 1 === Object.keys( tables ).length ? ' table' : ' tables' ) ) );
        if ( imported ) {
            countsEl.appendChild( document.createTextNode( ' still to import · ' + imported + ' imported (can be undone)' ) );
        }
        if ( ! n ) {
            countsEl.appendChild( document.createTextNode( ' — no lesson uses a wpDataTables glossary any more.' ) );
        }
        readBtn.hidden = ! n;
        return Object.keys( tables ).map( Number );
    }

    function lessonCell( lesson ) {
        var td = el( 'td', 'tbt-fc-imp-lesson' );
        var a = el( 'a', '', lesson.title || '#' + lesson.post );
        a.href = lesson.edit;
        td.appendChild( a );
        if ( 'publish' !== lesson.status ) {
            td.appendChild( el( 'span', 'tbt-fc-imp-post-status', ' — ' + lesson.status ) );
        }
        if ( lesson.view ) {
            var v = el( 'a', 'tbt-fc-imp-view', 'View' );
            v.href = lesson.view;
            v.target = '_blank';
            v.rel = 'noopener';
            td.appendChild( el( 'br' ) );
            td.appendChild( v );
        }
        return td;
    }

    function previewCell( read ) {
        var td = el( 'td', 'tbt-fc-imp-preview' );
        if ( ! read || ! read.ok ) {
            return td;
        }
        var ul = el( 'ul' );
        ( read.preview || [] ).forEach( function ( r ) {
            ul.appendChild( el( 'li', '', r[ 0 ] + ( r[ 1 ] ? ' — ' + r[ 1 ] : '' ) ) );
        } );
        td.appendChild( ul );
        if ( read.header ) {
            td.appendChild( el( 'div', 'tbt-fc-imp-meta', 'header dropped: ' + read.header.join( ' / ' ) ) );
        }
        if ( read.columns ) {
            td.appendChild( el( 'div', 'tbt-fc-imp-meta', 'columns ' + read.columns.join( ' and ' ) + ' used' ) );
        }
        return td;
    }

    function render() {
        var all = rows();
        var ready = all.filter( function ( r ) {
            return 'ready' === r.state;
        } );
        var failed = all.filter( function ( r ) {
            return 'failed' === r.state;
        } );
        var imported = all.filter( function ( r ) {
            return 'imported' === r.state;
        } );
        counts();
        renderPaste();

        tally.textContent = ready.length + ' ready · ' + failed.length + ' failed' + ( imported.length ? ' · ' + imported.length + ' imported' : '' );
        undoAllBtn.hidden = ! imported.length;
        copyBtn.hidden = ! failed.length;
        runBtn.hidden = ! ready.length;
        var ticked = ready.filter( function ( r ) {
            return checked[ r.key ];
        } ).length;
        runBtn.textContent = 'Import selected' + ( ticked ? ' (' + ticked + ')' : '' );
        runBtn.disabled = busy || ! ticked;

        var shown = all.filter( function ( r ) {
            return 'all' === filter || r.state === filter;
        } );
        allBox.checked = shown.some( function ( r ) {
            return 'ready' === r.state;
        } ) && shown.every( function ( r ) {
            return 'ready' !== r.state || checked[ r.key ];
        } );
        allBox.disabled = busy;

        tbody.textContent = '';
        if ( ! shown.length ) {
            var empty = el( 'tr' );
            var td = el( 'td', '', 'Nothing here.' );
            td.colSpan = 6;
            empty.appendChild( td );
            tbody.appendChild( empty );
        }
        shown.forEach( function ( r ) {
            tbody.appendChild( 'imported' === r.kind ? importedRow( r ) : rowFor( r ) );
        } );
    }

    function rowFor( r ) {
        var tr = el( 'tr', 'tbt-fc-imp-' + r.state );
        var th = el( 'th', 'check-column' );
        th.scope = 'row';
        var box = el( 'input' );
        box.type = 'checkbox';
        box.id = 'tbt-fc-imp-' + r.key.replace( ':', '-' );
        box.disabled = busy || 'ready' !== r.state;
        box.checked = 'ready' === r.state && !! checked[ r.key ];
        box.addEventListener( 'change', function () {
            checked[ r.key ] = box.checked;
            render();
        } );
        var label = el( 'label', 'screen-reader-text', 'Import ' + r.lesson.title );
        label.htmlFor = box.id;
        th.appendChild( label );
        th.appendChild( box );
        tr.appendChild( th );
        tr.appendChild( lessonCell( r.lesson ) );

        var table = el( 'td' );
        table.appendChild( el( 'strong', '', '#' + r.item.table ) );
        if ( r.read && r.read.title ) {
            table.appendChild( document.createTextNode( ' ' + r.read.title ) );
        }
        if ( r.read && r.read.type ) {
            table.appendChild( el( 'div', 'tbt-fc-imp-meta', TYPES[ r.read.type ] || r.read.type ) );
        }
        tr.appendChild( table );
        tr.appendChild( el( 'td', 'num', r.read && r.read.ok ? r.read.rows : '' ) );
        tr.appendChild( previewCell( r.read ) );

        var status = el( 'td', 'tbt-fc-imp-status' );
        if ( 'ready' === r.state ) {
            status.appendChild( el( 'span', 'tbt-fc-imp-ok', '✓ Ready' ) );
        } else if ( 'failed' === r.state ) {
            status.appendChild( el( 'span', 'tbt-fc-imp-bad', '✗ ' + r.note ) );
        } else {
            status.appendChild( el( 'span', 'tbt-fc-imp-grey', 'Not read yet' ) );
        }
        tr.appendChild( status );
        return tr;
    }

    function importedRow( r ) {
        var l = r.lesson;
        var tr = el( 'tr', 'tbt-fc-imp-imported' );
        tr.appendChild( el( 'th', 'check-column' ) );
        tr.appendChild( lessonCell( l ) );
        tr.appendChild( el( 'td', '', ( l.imported.tables || [] ).map( function ( id ) {
            return '#' + id;
        } ).join( ', ' ) ) );
        tr.appendChild( el( 'td', 'num', l.imported.rows ) );
        tr.appendChild( el( 'td' ) );
        var status = el( 'td', 'tbt-fc-imp-status' );
        status.appendChild( el( 'span', 'tbt-fc-imp-grey', 'Already imported' ) );
        status.appendChild( document.createTextNode( ' ' ) );
        var undo = el( 'button', 'button button-small', 'Undo' );
        undo.type = 'button';
        undo.disabled = busy;
        undo.addEventListener( 'click', function () {
            showError( '' );
            setBusy( true );
            undoPosts( [ l.post ] ).catch( function ( e ) {
                showError( 'Undo stopped: ' + e.message );
            } ).then( function () {
                progress.hidden = true;
                setBusy( false );
            } );
        } );
        status.appendChild( undo );
        tr.appendChild( status );
        return tr;
    }

    /* ---------- Actions ---------- */

    function readAll() {
        var ids = [];
        lessons.forEach( function ( l ) {
            ( l.items || [] ).forEach( function ( i ) {
                if ( ! i.global && ids.indexOf( i.table ) < 0 ) {
                    ids.push( i.table );
                }
            } );
        } );
        showError( '' );
        setBusy( true );
        return batches( ids, 10, function ( chunk ) {
            return api( 'read', { ids: chunk } ).then( function ( res ) {
                ( res.tables || [] ).forEach( function ( t ) {
                    reads[ t.id ] = t;
                    if ( t.ok ) {
                        lessons.forEach( function ( l ) {
                            ( l.items || [] ).forEach( function ( i ) {
                                if ( i.table === t.id && ! i.global ) {
                                    checked[ l.post + ':' + i.table ] = true;
                                }
                            } );
                        } );
                    }
                } );
            } );
        }, function ( done, total ) {
            return done < total ? 'Reading ' + done + ' of ' + total + '…' : 'Read ' + total + ' tables.';
        } ).catch( function ( e ) {
            showError( 'Reading stopped: ' + e.message );
        } ).then( function () {
            rescanBtn.hidden = false;
            setBusy( false );
        } );
    }

    function runSelected() {
        var byPost = {};
        var order = [];
        rows().forEach( function ( r ) {
            if ( 'ready' === r.state && checked[ r.key ] ) {
                if ( ! byPost[ r.lesson.post ] ) {
                    byPost[ r.lesson.post ] = { post: r.lesson.post, tables: [] };
                    order.push( r.lesson.post );
                }
                byPost[ r.lesson.post ].tables.push( r.item.table );
            }
        } );
        var items = order.map( function ( p ) {
            return byPost[ p ];
        } );
        if ( ! items.length ) {
            return;
        }
        showError( '' );
        setBusy( true );
        batches( items, 20, function ( chunk ) {
            return api( 'run', { items: chunk } ).then( function ( res ) {
                ( res.results || [] ).forEach( function ( r ) {
                    var lesson = find( r.post );
                    if ( ! lesson ) {
                        return;
                    }
                    if ( r.ok ) {
                        var done = byPost[ r.post ] ? byPost[ r.post ].tables : [];
                        lesson.items = ( lesson.items || [] ).filter( function ( i ) {
                            return done.indexOf( i.table ) < 0;
                        } );
                        done.forEach( function ( id ) {
                            delete checked[ r.post + ':' + id ];
                        } );
                        lesson.imported = r.imported;
                        delete failedRun[ r.post ];
                    } else {
                        failedRun[ r.post ] = r.note || 'unknown error';
                    }
                } );
            } );
        }, function ( done, total ) {
            return done < total ? 'Importing ' + done + ' of ' + total + '…' : 'Imported ' + total + ( 1 === total ? ' lesson.' : ' lessons.' ) + ' Purge the LiteSpeed cache when you are done.';
        } ).catch( function ( e ) {
            showError( 'Import stopped: ' + e.message );
        } ).then( function () {
            setBusy( false );
        } );
    }

    function undoPosts( posts ) {
        return batches( posts, 20, function ( chunk ) {
            return api( 'undo', { posts: chunk } ).then( function ( res ) {
                ( res.results || [] ).forEach( function ( r ) {
                    var lesson = find( r.post );
                    if ( lesson && r.ok ) {
                        lesson.imported = null;
                        lesson.items = r.items || [];
                        lesson.quizlet = r.quizlet || [];
                        lesson.multidecker = r.multidecker || [];
                        delete failedRun[ r.post ];
                    }
                } );
            } );
        }, function ( done, total ) {
            return done < total ? 'Undoing ' + done + ' of ' + total + '…' : 'Undone ' + total + ( 1 === total ? ' lesson.' : ' lessons.' );
        } );
    }

    function find( post ) {
        for ( var i = 0; i < lessons.length; i++ ) {
            if ( lessons[ i ].post === post ) {
                return lessons[ i ];
            }
        }
        return null;
    }

    function copyFailed() {
        var lines = rows().filter( function ( r ) {
            return 'failed' === r.state;
        } ).map( function ( r ) {
            return r.lesson.title + ' — ' + r.lesson.edit + ' — ' + r.item.table + ' — ' + r.note;
        } );
        var text = lines.join( '\n' );
        var done = function () {
            copyBtn.textContent = 'Copied ' + lines.length;
            setTimeout( function () {
                copyBtn.textContent = 'Copy failed list';
            }, 2000 );
        };
        var fallback = function () {
            var ta = el( 'textarea' );
            ta.value = text;
            ta.setAttribute( 'readonly', '' );
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild( ta );
            ta.select();
            try {
                document.execCommand( 'copy' );
            } catch ( e ) {}
            document.body.removeChild( ta );
            done();
        };
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then( done, fallback );
        } else {
            fallback();
        }
    }

    /* ---------- Wiring ---------- */

    readBtn.addEventListener( 'click', readAll );
    rescanBtn.addEventListener( 'click', function () {
        showError( '' );
        setBusy( true );
        api( 'rescan', {} ).then( function () {
            reads = {};
            checked = {};
            busy = false;
            return readAll();
        } ).catch( function ( e ) {
            showError( 'Rescan stopped: ' + e.message );
            setBusy( false );
        } );
    } );
    runBtn.addEventListener( 'click', runSelected );
    copyBtn.addEventListener( 'click', copyFailed );

    undoAllBtn.addEventListener( 'click', function () {
        var n = lessons.filter( function ( l ) {
            return l.imported;
        } ).length;
        confirmBox.querySelector( 'strong' ).textContent = 'Undo all ' + n + ( 1 === n ? ' import?' : ' imports?' );
        confirmBox.hidden = false;
        confirmBox.querySelector( '[data-confirm="no"]' ).focus();
    } );
    confirmBox.addEventListener( 'click', function ( e ) {
        var b = e.target.closest( '[data-confirm]' );
        if ( ! b ) {
            return;
        }
        confirmBox.hidden = true;
        if ( 'yes' !== b.getAttribute( 'data-confirm' ) ) {
            undoAllBtn.focus();
            return;
        }
        var posts = lessons.filter( function ( l ) {
            return l.imported;
        } ).map( function ( l ) {
            return l.post;
        } );
        showError( '' );
        setBusy( true );
        undoPosts( posts ).catch( function ( e2 ) {
            showError( 'Undo stopped: ' + e2.message );
        } ).then( function () {
            setBusy( false );
        } );
    } );

    Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-imp-chips [data-filter]' ), function ( b ) {
        b.addEventListener( 'click', function () {
            filter = b.getAttribute( 'data-filter' );
            Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-imp-chips [data-filter]' ), function ( x ) {
                x.setAttribute( 'aria-pressed', String( x === b ) );
            } );
            render();
        } );
    } );

    allBox.addEventListener( 'change', function () {
        rows().forEach( function ( r ) {
            if ( 'ready' === r.state && ( 'all' === filter || r.state === filter ) ) {
                checked[ r.key ] = allBox.checked;
            }
        } );
        render();
    } );

    /* ---------- Paste words (2.4.1) ---------- */

    var pasteBadge = $( 'tbt-fc-paste-badge' );
    var pasteTop = $( 'tbt-fc-paste-top' );
    var pasteSearch = $( 'tbt-fc-paste-search' );
    var pasteTable = $( 'tbt-fc-paste-table' );
    var pasteBody = $( 'tbt-fc-paste-rows' );
    var pasteNone = $( 'tbt-fc-paste-none' );
    var pasteDone = $( 'tbt-fc-paste-done' );
    var pasteRows = {}; // post → { tr, saving, saved }
    var HEADER_TERM = /^(item|items|word|words|expression|expressions|english|phrase|phrases|term|terms|vocabulary|glossary)\b/i;
    var HEADER_TRANSLATION = /(translation|polish|polski|tłumaczenie)/i;
    var SEP_LABEL = { '\t': 'tab-separated', '-': 'separated by " - "', ';': 'separated by ";"', ',': 'separated by ","', '': 'no separator found: words only' };

    // Mirrors tbt_fc_paste_separator() / tbt_fc_parse_pasted() and, roughly,
    // the header rule and clean_rows(). Preview only.
    function pasteSeparator( lines ) {
        var counts = { '-': 0, ';': 0, ',': 0 };
        for ( var i = 0; i < lines.length; i++ ) {
            if ( lines[ i ].indexOf( '\t' ) >= 0 ) {
                return '\t';
            }
            if ( / [-–] /.test( lines[ i ] ) ) {
                counts[ '-' ]++;
            }
            if ( lines[ i ].indexOf( ';' ) >= 0 ) {
                counts[ ';' ]++;
            }
            if ( lines[ i ].indexOf( ',' ) >= 0 ) {
                counts[ ',' ]++;
            }
        }
        var best = '';
        var max = 0;
        [ '-', ';', ',' ].forEach( function ( sep ) {
            if ( counts[ sep ] > max ) {
                best = sep;
                max = counts[ sep ];
            }
        } );
        return best;
    }

    function parsePaste( text ) {
        var lines = String( text || '' ).replace( /^﻿/, '' ).split( /\r\n|\r|\n/ ).filter( function ( l ) {
            return '' !== l.trim();
        } );
        var sep = pasteSeparator( lines );
        var rows = lines.map( function ( line ) {
            var at = -1;
            var len = 0;
            if ( '-' === sep ) {
                var m = / [-–] /.exec( line );
                at = m ? m.index : -1;
                len = 3;
            } else if ( sep ) {
                at = line.indexOf( sep );
                len = sep.length;
            }
            return at < 0 ? [ line, '' ] : [ line.slice( 0, at ), line.slice( at + len ) ];
        } );
        var clean = function ( v ) {
            return v.replace( /<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?\/?>/gi, ' ' ).replace( /[\s   ﻿]+/g, ' ' ).trim();
        };
        if ( rows.length && ( HEADER_TERM.test( rows[ 0 ][ 0 ].trim() ) || HEADER_TRANSLATION.test( rows[ 0 ][ 1 ] ) ) ) {
            rows.shift();
        }
        var seen = {};
        var words = 0;
        rows.forEach( function ( r ) {
            var term = clean( r[ 0 ] );
            var key = term.toLowerCase();
            if ( term && ! seen[ key ] ) {
                seen[ key ] = true;
                words++;
            }
        } );
        return { words: words, lines: lines.length, sep: sep };
    }

    function pending() {
        return lessons.filter( function ( l ) {
            return l.items && l.items.length;
        } ).sort( function ( a, b ) {
            return String( a.title ).localeCompare( String( b.title ) );
        } );
    }

    function setLinks( td, lesson ) {
        var links = [];
        ( lesson.quizlet || [] ).forEach( function ( id, i ) {
            links.push( [ 'Open set ' + ( i + 1 ), 'https://quizlet.com/' + encodeURIComponent( id ) + '/' ] );
        } );
        ( lesson.multidecker || [] ).forEach( function ( slug, i ) {
            links.push( [ 'Open Multidecker ' + ( i + 1 ), 'https://multidecker.com/list/' + encodeURIComponent( slug ) + '/' ] );
        } );
        if ( ! links.length ) {
            td.appendChild( el( 'span', 'tbt-fc-imp-grey', 'No Quizlet set in this lesson' ) );
            return;
        }
        links.forEach( function ( link, i ) {
            if ( i ) {
                td.appendChild( el( 'br' ) );
            }
            var a = el( 'a', '', link[ 0 ] );
            a.href = link[ 1 ];
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            td.appendChild( a );
        } );
    }

    function pasteRow( lesson ) {
        var state = { saving: false, saved: false };
        var tr = el( 'tr', 'tbt-fc-paste-row' );
        var lessonTd = lessonCell( lesson );
        if ( lesson.edit ) {
            var e = el( 'a', 'tbt-fc-imp-view', 'Edit' );
            e.href = lesson.edit;
            lessonTd.appendChild( lesson.view ? document.createTextNode( ' · ' ) : el( 'br' ) );
            lessonTd.appendChild( e );
        }
        tr.appendChild( lessonTd );

        var links = el( 'td', 'tbt-fc-paste-links' );
        setLinks( links, lesson );
        tr.appendChild( links );

        var words = el( 'td', 'tbt-fc-paste-words' );
        var box = el( 'textarea', 'large-text' );
        box.rows = 4;
        box.placeholder = 'Paste the Quizlet export here';
        box.setAttribute( 'aria-label', 'Words for ' + ( lesson.title || '#' + lesson.post ) );
        var info = el( 'p', 'tbt-fc-imp-meta tbt-fc-paste-info' );
        var err = el( 'p', 'tbt-fc-imp-bad tbt-fc-paste-error' );
        err.hidden = true;
        words.appendChild( box );
        words.appendChild( info );
        words.appendChild( err );
        tr.appendChild( words );

        var act = el( 'td', 'tbt-fc-paste-act' );
        var save = el( 'button', 'button button-primary', 'Save' );
        save.type = 'button';
        act.appendChild( save );
        tr.appendChild( act );

        var preview = function () {
            if ( state.saved ) {
                save.disabled = true;
                return;
            }
            var p = parsePaste( box.value );
            info.textContent = p.lines ? p.words + ( 1 === p.words ? ' word' : ' words' ) + ' read · ' + SEP_LABEL[ p.sep ] : '';
            save.disabled = state.saving || state.saved || ! box.value.trim();
        };
        box.addEventListener( 'input', function () {
            err.hidden = true;
            preview();
        } );
        preview();

        save.addEventListener( 'click', function () {
            if ( state.saving || state.saved ) {
                return;
            }
            state.saving = true;
            err.hidden = true;
            preview();
            api( 'paste', { post_id: lesson.post, text: box.value } ).then( function ( res ) {
                state.saved = true;
                box.disabled = true;
                tr.classList.add( 'tbt-fc-paste-saved' );
                info.textContent = '';
                info.appendChild( el( 'span', 'tbt-fc-imp-ok', '✓ Saved · ' + res.rows + ( 1 === res.rows ? ' word' : ' words' ) ) );
                window.setTimeout( function () {
                    tr.classList.add( 'tbt-fc-paste-gone' );
                    window.setTimeout( function () {
                        ( lesson.items || [] ).forEach( function ( i ) {
                            delete checked[ lesson.post + ':' + i.table ];
                        } );
                        delete failedRun[ lesson.post ];
                        lesson.items = [];
                        lesson.imported = res.lesson || { tables: [], rows: res.rows, at: '' };
                        if ( tr.parentNode ) {
                            tr.parentNode.removeChild( tr );
                        }
                        delete pasteRows[ lesson.post ];
                        render();
                    }, 400 );
                }, 1500 );
            } ).catch( function ( e ) {
                err.textContent = e.message;
                err.hidden = false;
            } ).then( function () {
                state.saving = false;
                preview();
            } );
        } );

        state.tr = tr;
        return state;
    }

    function renderPaste() {
        var list = pending();
        var n = list.length;
        var q = pasteSearch.value.trim().toLowerCase();
        var keep = {};
        var shown = 0;

        list.forEach( function ( lesson ) {
            var row = pasteRows[ lesson.post ] || ( pasteRows[ lesson.post ] = pasteRow( lesson ) );
            keep[ lesson.post ] = true;
            row.tr.hidden = !! q && String( lesson.title ).toLowerCase().indexOf( q ) < 0;
            if ( ! row.tr.hidden ) {
                shown++;
            }
            pasteBody.appendChild( row.tr ); // Moves it into A–Z order.
        } );
        Object.keys( pasteRows ).forEach( function ( post ) {
            if ( ! keep[ post ] && ! pasteRows[ post ].saved ) {
                var tr = pasteRows[ post ].tr;
                if ( tr.parentNode ) {
                    tr.parentNode.removeChild( tr );
                }
                delete pasteRows[ post ];
            }
        } );

        pasteBadge.textContent = n ? String( n ) : '';
        pasteBadge.hidden = ! n;
        pasteTop.textContent = '';
        pasteTop.appendChild( el( 'strong', '', n ) );
        pasteTop.appendChild( document.createTextNode( ( 1 === n ? ' lesson still uses' : ' lessons still use' ) + ' an old wpDataTables glossary. Open the Quizlet set, choose ' ) );
        pasteTop.appendChild( el( 'strong', '', '⋯ → Export' ) );
        pasteTop.appendChild( document.createTextNode( ', copy the text, paste it here and press ' ) );
        pasteTop.appendChild( el( 'strong', '', 'Save' ) );
        pasteTop.appendChild( document.createTextNode( '.' ) );

        var collapsing = Object.keys( pasteRows ).length > 0;
        pasteTop.hidden = ! n;
        pasteSearch.parentNode.hidden = ! n;
        pasteTable.hidden = ! n && ! collapsing;
        pasteNone.hidden = ! n || shown > 0;
        pasteDone.hidden = n > 0 || collapsing;
    }

    pasteSearch.addEventListener( 'input', renderPaste );

    // Tabs.
    function showTab( name ) {
        Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-imp .nav-tab[data-tab]' ), function ( t ) {
            var on = t.getAttribute( 'data-tab' ) === name;
            t.classList.toggle( 'nav-tab-active', on );
            if ( on ) {
                t.setAttribute( 'aria-current', 'page' );
            } else {
                t.removeAttribute( 'aria-current' );
            }
        } );
        $( 'tbt-fc-imp-panel-tables' ).hidden = 'tables' !== name;
        $( 'tbt-fc-imp-panel-paste' ).hidden = 'paste' !== name;
    }
    Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-imp .nav-tab[data-tab]' ), function ( t ) {
        t.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            showTab( t.getAttribute( 'data-tab' ) );
            if ( window.history && window.history.replaceState ) {
                window.history.replaceState( null, '', t.getAttribute( 'href' ) );
            }
        } );
    } );
    showTab( '#paste-words' === window.location.hash ? 'paste' : 'tables' );

    list.hidden = false;
    render();
}() );
