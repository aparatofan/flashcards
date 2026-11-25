/**
 * TBT Flashcards — lesson glossary ([tbt_flashcards_glossary]).
 *
 * Renders search, sort and paging from the rows the server embeds in
 * data-rows. Card text is untrusted: it is only ever set with textContent
 * (highlights are built as <mark> nodes), never as HTML. Each glossary on a
 * page keeps its own state.
 *
 * With data-dict="1" (logged in, TBT Dictionary 0.3.0+) a third column adds
 * words to My Dictionary: one POST lookup on load, then POST words from a
 * small modal, as the approved prototype (tbt-search-table, Lesson glossary).
 */
( function () {
    'use strict';

    var PER = 10;

    // Fold one character for matching: lower case, accents removed, ł → l.
    // Folding character by character keeps indices aligned with the original
    // text, so highlights land on the right letters.
    function foldChar( ch ) {
        var f = ch.toLowerCase();
        if ( f.normalize ) {
            f = f.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
        }
        if ( 'ł' === f ) {
            f = 'l';
        }
        return f;
    }

    // Folded text plus, for each folded position, the index in the original.
    function foldMap( text ) {
        var out = '';
        var map = [];
        var chars = Array.from ? Array.from( text ) : text.split( '' );
        var pos = 0;
        for ( var i = 0; i < chars.length; i++ ) {
            var f = foldChar( chars[ i ] );
            for ( var j = 0; j < f.length; j++ ) {
                out += f.charAt( j );
                map.push( pos );
            }
            pos += chars[ i ].length;
        }
        map.push( pos );
        return { text: out, map: map };
    }

    function fold( text ) {
        return foldMap( text ).text;
    }

    function format( pattern ) {
        var args = Array.prototype.slice.call( arguments, 1 );
        var n = 0;
        return String( pattern ).replace( /%(?:(\d+)\$)?([ds])/g, function ( m, idx ) {
            var v = idx ? args[ parseInt( idx, 10 ) - 1 ] : args[ n++ ];
            return undefined === v ? '' : String( v );
        } );
    }

    /* ---------- My Dictionary (shared by every glossary on the page) ---------- */

    var LOOKUP_MAX = 300; // Dictionary's TBTDIC_Words::LOOKUP_MAX.
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var instances = [];
    var groups = {}; // fold(name) → { id, name, count }
    var modal = null;
    var toastEl = null;
    var toastTimer = null;

    function sameTerm( a, b ) {
        return String( a ).trim().toLowerCase() === String( b ).trim().toLowerCase();
    }

    function mergeGroups( list ) {
        ( Array.isArray( list ) ? list : [] ).forEach( function ( g ) {
            if ( g && 'string' === typeof g.name && g.name ) {
                groups[ fold( g.name ) ] = { id: parseInt( g.id, 10 ) || 0, name: g.name, count: parseInt( g.word_count, 10 ) || 0 };
            }
        } );
    }

    function groupById( id ) {
        for ( var k in groups ) {
            if ( Object.prototype.hasOwnProperty.call( groups, k ) && groups[ k ].id === id ) {
                return groups[ k ];
            }
        }
        return null;
    }

    function storageKey( dict ) {
        return 'tbtfc:' + dict.user + ':dictGroup';
    }

    function savedGroup( dict ) {
        try {
            return window.localStorage.getItem( storageKey( dict ) ) || '';
        } catch ( e ) {
            return '';
        }
    }

    function saveGroup( dict, name ) {
        try {
            window.localStorage.setItem( storageKey( dict ), name );
        } catch ( e ) {}
    }

    // POST to the Dictionary API. Resolves { status, json } for any HTTP
    // answer; rejects only on a network failure.
    function dictPost( dict, path, body ) {
        return window.fetch( dict.api + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-WP-Nonce': dict.nonce },
            body: JSON.stringify( body || {} )
        } ).then( function ( res ) {
            return res.json().catch( function () {
                return {};
            } ).then( function ( json ) {
                return { status: res.status, json: json || {} };
            } );
        } );
    }

    // Mark a term as in the dictionary in every glossary on the page.
    function markHave( term, group ) {
        instances.forEach( function ( inst ) {
            var changed = false;
            inst.data.forEach( function ( r, i ) {
                if ( sameTerm( r[ 0 ], term ) ) {
                    inst.dict.have[ i ] = group;
                    changed = true;
                }
            } );
            if ( changed ) {
                inst.render();
            }
        } );
    }

    function toast( text ) {
        if ( ! toastEl ) {
            toastEl = document.createElement( 'div' );
            toastEl.className = 'tbt-fc-gl tbt-fc-gl-toast';
            toastEl.setAttribute( 'role', 'status' );
            toastEl.setAttribute( 'aria-live', 'polite' );
            toastEl.hidden = true;
            document.body.appendChild( toastEl );
        }
        toastEl.textContent = text;
        toastEl.hidden = false;
        // Restart the entry animation.
        toastEl.classList.remove( 'tbt-fc-gl-toast-in' );
        void toastEl.offsetWidth;
        toastEl.classList.add( 'tbt-fc-gl-toast-in' );
        clearTimeout( toastTimer );
        toastTimer = setTimeout( function () {
            toastEl.hidden = true;
        }, 2400 );
    }

    function el( tag, cls, text ) {
        var node = document.createElement( tag );
        if ( cls ) {
            node.className = cls;
        }
        if ( undefined !== text ) {
            node.textContent = text;
        }
        return node;
    }

    function plusIcon() {
        var svg = document.createElementNS( SVG_NS, 'svg' );
        svg.setAttribute( 'width', '14' );
        svg.setAttribute( 'height', '14' );
        svg.setAttribute( 'viewBox', '0 0 24 24' );
        svg.setAttribute( 'fill', 'none' );
        svg.setAttribute( 'stroke', 'currentColor' );
        svg.setAttribute( 'stroke-width', '2.5' );
        svg.setAttribute( 'stroke-linecap', 'round' );
        svg.setAttribute( 'aria-hidden', 'true' );
        svg.setAttribute( 'focusable', 'false' );
        var path = document.createElementNS( SVG_NS, 'path' );
        path.setAttribute( 'd', 'M12 5v14M5 12h14' );
        svg.appendChild( path );
        return svg;
    }

    // The modal: built once, reused by every glossary on the page.
    function buildModal() {
        var m = {};
        m.ov = el( 'div', 'tbt-fc-gl tbt-fc-gl-ov' );
        m.ov.hidden = true;
        m.box = el( 'div', 'tbt-fc-gl-modal' );
        m.box.setAttribute( 'role', 'dialog' );
        m.box.setAttribute( 'aria-modal', 'true' );
        m.box.setAttribute( 'aria-labelledby', 'tbt-fc-gl-mh' );
        m.title = el( 'h2', 'tbt-fc-gl-mtitle' );
        m.title.id = 'tbt-fc-gl-mh';
        m.word = el( 'div', 'tbt-fc-gl-mword' );

        var f1 = el( 'div', 'tbt-fc-gl-field' );
        m.transLabel = el( 'label', 'tbt-fc-gl-label' );
        m.transLabel.htmlFor = 'tbt-fc-gl-mt';
        m.trans = el( 'input', 'tbt-fc-gl-minput' );
        m.trans.id = 'tbt-fc-gl-mt';
        m.trans.type = 'text';
        m.trans.autocomplete = 'off';
        f1.appendChild( m.transLabel );
        f1.appendChild( m.trans );

        var f2 = el( 'div', 'tbt-fc-gl-field' );
        m.groupLabel = el( 'label', 'tbt-fc-gl-label' );
        m.groupLabel.htmlFor = 'tbt-fc-gl-mg';
        m.group = el( 'input', 'tbt-fc-gl-minput' );
        m.group.id = 'tbt-fc-gl-mg';
        m.group.type = 'text';
        m.group.autocomplete = 'off';
        m.group.setAttribute( 'list', 'tbt-fc-gl-mgl' );
        m.group.setAttribute( 'aria-describedby', 'tbt-fc-gl-mgh' );
        m.list = el( 'datalist' );
        m.list.id = 'tbt-fc-gl-mgl';
        m.hint = el( 'div', 'tbt-fc-gl-hint' );
        m.hint.id = 'tbt-fc-gl-mgh';
        m.hint.setAttribute( 'aria-live', 'polite' );
        f2.appendChild( m.groupLabel );
        f2.appendChild( m.group );
        f2.appendChild( m.list );
        f2.appendChild( m.hint );

        var act = el( 'div', 'tbt-fc-gl-mact' );
        m.cancel = el( 'button', 'tbt-fc-gl-btn tbt-fc-gl-ghost' );
        m.cancel.type = 'button';
        m.add = el( 'button', 'tbt-fc-gl-btn' );
        m.add.type = 'button';
        act.appendChild( m.cancel );
        act.appendChild( m.add );

        m.box.appendChild( m.title );
        m.box.appendChild( m.word );
        m.box.appendChild( f1 );
        m.box.appendChild( f2 );
        m.box.appendChild( act );
        m.ov.appendChild( m.box );
        document.body.appendChild( m.ov );

        m.ov.addEventListener( 'mousedown', function ( e ) {
            if ( e.target === m.ov ) {
                closeModal();
            }
        } );
        // On the document, so Escape and Tab still work when focus fell to
        // <body> (the Add button is disabled while saving).
        document.addEventListener( 'keydown', function ( e ) {
            if ( m.ov.hidden ) {
                return;
            }
            if ( 'Escape' === e.key || 'Esc' === e.key ) {
                e.preventDefault();
                closeModal();
                return;
            }
            if ( 'Tab' !== e.key ) {
                return;
            }
            var items = [ m.trans, m.group, m.cancel, m.add ].filter( function ( x ) {
                return ! x.disabled;
            } );
            var at = items.indexOf( document.activeElement );
            if ( e.shiftKey && at <= 0 ) {
                e.preventDefault();
                items[ items.length - 1 ].focus();
            } else if ( ! e.shiftKey && at === items.length - 1 ) {
                e.preventDefault();
                items[ 0 ].focus();
            } else if ( -1 === at ) {
                e.preventDefault();
                items[ 0 ].focus();
            }
        } );
        m.cancel.addEventListener( 'click', closeModal );
        m.group.addEventListener( 'input', function () {
            groupHint();
        } );
        m.group.addEventListener( 'keydown', function ( e ) {
            if ( 'Enter' === e.key ) {
                e.preventDefault();
                submit();
            }
        } );
        m.add.addEventListener( 'click', submit );
        return m;
    }

    function groupHint() {
        var t = modal.state.inst.t;
        var v = modal.group.value.trim();
        modal.hint.className = 'tbt-fc-gl-hint';
        modal.hint.textContent = '';
        if ( ! v ) {
            modal.hint.textContent = t.hintEmpty || 'Type a group name or pick one from the list.';
            return;
        }
        var g = groups[ fold( v ) ];
        if ( g ) {
            modal.hint.textContent = 1 === g.count ? ( t.hintOne || '1 word in this group.' ) : format( t.hintMany || '%d words in this group.', g.count );
            return;
        }
        modal.hint.appendChild( el( 'strong', '', t.hintNewLabel || 'New group:' ) );
        modal.hint.appendChild( document.createTextNode( ' ' + format( t.hintNew || '“%s” will be created when you add the word.', v ) ) );
    }

    function modalError( text ) {
        modal.hint.className = 'tbt-fc-gl-hint tbt-fc-gl-err';
        modal.hint.textContent = text;
        modal.group.focus();
    }

    function openModal( inst, idx, btn ) {
        if ( ! modal ) {
            modal = buildModal();
        }
        var t = inst.t;
        var saved = savedGroup( inst.dict );
        modal.state = { inst: inst, idx: idx, btn: btn, focus: btn };

        modal.title.textContent = t.dictTitle || 'Add to My Dictionary';
        modal.word.textContent = inst.data[ idx ][ 0 ];
        modal.transLabel.textContent = t.dictTrans || 'Translation';
        modal.groupLabel.textContent = t.dictGroup || 'Group';
        modal.cancel.textContent = t.dictCancel || 'Cancel';
        modal.add.textContent = t.dictSubmit || 'Add word';
        modal.add.disabled = false;
        modal.trans.value = inst.data[ idx ][ 1 ];
        modal.group.value = saved;

        modal.list.textContent = '';
        Object.keys( groups ).map( function ( k ) {
            return groups[ k ].name;
        } ).sort( function ( a, b ) {
            return a.localeCompare( b, undefined, { sensitivity: 'base' } );
        } ).forEach( function ( name ) {
            var o = document.createElement( 'option' );
            o.value = name;
            modal.list.appendChild( o );
        } );
        groupHint();

        modal.ov.hidden = false;
        ( saved ? modal.add : modal.group ).focus();
    }

    function closeModal() {
        if ( ! modal || modal.ov.hidden ) {
            return;
        }
        modal.ov.hidden = true;
        var f = modal.state && modal.state.focus;
        if ( f && document.body.contains( f ) ) {
            f.focus();
        }
    }

    // After a row turned ✓, return focus to its new cell.
    function closeOnRow( inst, idx ) {
        var cell = inst.root.querySelector( '.tbt-fc-gl-in[data-idx="' + idx + '"]' );
        if ( cell ) {
            modal.state.focus = cell;
        }
        closeModal();
    }

    function submit() {
        var st = modal.state;
        var inst = st.inst;
        var t = inst.t;
        var dict = inst.dict;
        var term = inst.data[ st.idx ][ 0 ];
        var typed = modal.group.value.trim();
        if ( ! typed ) {
            modalError( t.needGroup || 'Pick a group or type a new one.' );
            modal.group.focus();
            return;
        }
        if ( modal.add.disabled ) {
            return;
        }
        var known = groups[ fold( typed ) ];
        var name = known ? known.name : typed;
        modal.add.disabled = true;

        dictPost( dict, 'words', { group_name: name, term: term, translation: modal.trans.value.trim() } ).then( function ( r ) {
            var j = r.json;
            if ( 200 === r.status ) {
                var g = j.group && 'string' === typeof j.group.name ? j.group : null;
                var gname = g ? g.name : name;
                groups[ fold( gname ) ] = {
                    id: g ? parseInt( g.id, 10 ) || 0 : ( known ? known.id : 0 ),
                    name: gname,
                    count: g ? parseInt( g.word_count, 10 ) || 0 : ( known ? known.count + 1 : 1 )
                };
                saveGroup( dict, gname );
                markHave( term, gname );
                closeOnRow( inst, st.idx );
                var n = parseInt( j.points, 10 ) || 0;
                toast( n > 0 ? format( 1 === n ? ( t.toastOne || '+%1$d point · added to %2$s' ) : ( t.toastMany || '+%1$d points · added to %2$s' ), n, gname ) : format( t.toastNone || 'Added to %s', gname ) );
                if ( j.word && j.word.id ) {
                    // Phonetics and an example, filled in by Dictionary; not shown here.
                    dictPost( dict, 'words/' + parseInt( j.word.id, 10 ) + '/fill', {} ).catch( function () {} );
                }
                return;
            }
            if ( 409 === r.status ) {
                var data = j.data || {};
                var byId = groupById( parseInt( data.group_id, 10 ) || 0 );
                var m = 'string' === typeof j.message ? j.message.match( /\(([^()]*)\)\s*$/ ) : null;
                var where = byId ? byId.name : ( m ? m[ 1 ] : name );
                markHave( term, where );
                closeOnRow( inst, st.idx );
                toast( format( t.already || 'Already in your dictionary (%s)', where ) );
                return;
            }
            modal.add.disabled = false;
            modalError( 'rest_cookie_invalid_nonce' === j.code
                ? ( t.expired || 'Your session has expired. Reload the page and try again.' )
                : ( 'string' === typeof j.message && j.message ? j.message : ( t.failed || 'Could not add the word. Try again.' ) ) );
        } ).catch( function () {
            modal.add.disabled = false;
            modalError( t.failed || 'Could not add the word. Try again.' );
        } );
    }

    // One lookup for the glossary's terms (split only past Dictionary's limit).
    function lookup( inst ) {
        var dict = inst.dict;
        var terms = inst.data.map( function ( r ) {
            return r[ 0 ];
        } );
        var chunks = [];
        for ( var i = 0; i < terms.length; i += LOOKUP_MAX ) {
            chunks.push( terms.slice( i, i + LOOKUP_MAX ) );
        }
        var done = function () {
            dict.ready = true;
            inst.render();
        };
        if ( ! window.fetch || ! chunks.length ) {
            done();
            return;
        }
        Promise.all( chunks.map( function ( c ) {
            return dictPost( dict, 'lookup', { terms: c } );
        } ) ).then( function ( answers ) {
            answers.forEach( function ( r ) {
                if ( 200 !== r.status ) {
                    return;
                }
                mergeGroups( r.json.groups );
                var have = r.json.have && 'object' === typeof r.json.have ? r.json.have : {};
                inst.data.forEach( function ( row, idx ) {
                    if ( Object.prototype.hasOwnProperty.call( have, row[ 0 ] ) && 'string' === typeof have[ row[ 0 ] ] ) {
                        dict.have[ idx ] = have[ row[ 0 ] ];
                    }
                } );
            } );
            done();
        } ).catch( done );
    }

    function parse( json, fallback ) {
        try {
            var v = JSON.parse( json || '' );
            return v || fallback;
        } catch ( e ) {
            return fallback;
        }
    }

    function init( root ) {
        if ( root.getAttribute( 'data-ready' ) ) {
            return;
        }
        root.setAttribute( 'data-ready', '1' );

        var data = parse( root.getAttribute( 'data-rows' ), [] ).filter( function ( r ) {
            return Array.isArray( r ) && 'string' === typeof r[ 0 ];
        } ).map( function ( r ) {
            return [ r[ 0 ], 'string' === typeof r[ 1 ] ? r[ 1 ] : '' ];
        } );
        var t = parse( root.getAttribute( 'data-i18n' ), {} );
        var folded = data.map( function ( r ) {
            return [ fold( r[ 0 ] ), fold( r[ 1 ] ) ];
        } );

        var q = function ( sel ) { return root.querySelector( sel ); };
        var input = q( '.tbt-fc-gl-input' );
        var clearBtn = q( '.tbt-fc-gl-clear' );
        var count = q( '.tbt-fc-gl-count' );
        var tbody = q( '.tbt-fc-gl-rows' );
        var pager = q( '.tbt-fc-gl-pager' );
        var prev = q( '.tbt-fc-gl-prev' );
        var next = q( '.tbt-fc-gl-next' );
        var range = q( '.tbt-fc-gl-range' );
        var allBtn = q( '.tbt-fc-gl-all' );
        var heads = root.querySelectorAll( 'th[data-col]' );
        var dict = '1' === root.getAttribute( 'data-dict' ) ? {
            api: root.getAttribute( 'data-dict-api' ) || '',
            nonce: root.getAttribute( 'data-nonce' ) || '',
            user: root.getAttribute( 'data-user' ) || '0',
            have: data.map( function () {
                return '';
            } ),
            ready: false
        } : null;
        var inst = { root: root, data: data, t: t, dict: dict, render: null };

        var page = 0;
        var showAll = false;
        var sortCol = null;
        var sortDir = 1;
        var query = '';

        function needle() {
            return fold( query.trim() );
        }

        function currentRows() {
            var n = needle();
            var rows = [];
            for ( var i = 0; i < data.length; i++ ) {
                if ( ! n || folded[ i ][ 0 ].indexOf( n ) > -1 || folded[ i ][ 1 ].indexOf( n ) > -1 ) {
                    rows.push( i );
                }
            }
            if ( null !== sortCol ) {
                var locale = sortCol ? 'pl' : 'en';
                rows.sort( function ( a, b ) {
                    return sortDir * data[ a ][ sortCol ].localeCompare( data[ b ][ sortCol ], locale, { sensitivity: 'base' } ) || a - b;
                } );
            }
            return rows;
        }

        function highlight( cell, text, n ) {
            if ( ! n ) {
                cell.textContent = text;
                return;
            }
            var fm = foldMap( text );
            var from = 0;
            var i = 0;
            var at;
            while ( ( at = fm.text.indexOf( n, i ) ) > -1 ) {
                var start = fm.map[ at ];
                var end = fm.map[ at + n.length ];
                if ( start > from ) {
                    cell.appendChild( document.createTextNode( text.slice( from, start ) ) );
                }
                var mark = document.createElement( 'mark' );
                mark.className = 'tbt-fc-gl-mark';
                mark.textContent = text.slice( start, end );
                cell.appendChild( mark );
                from = end;
                i = at + n.length;
            }
            if ( from < text.length ) {
                cell.appendChild( document.createTextNode( text.slice( from ) ) );
            }
        }

        function render() {
            var rows = currentRows();
            var total = rows.length;
            var pages = Math.max( 1, Math.ceil( total / PER ) );
            var n = needle();
            if ( page >= pages ) {
                page = pages - 1;
            }
            var shown = showAll ? rows : rows.slice( page * PER, page * PER + PER );

            tbody.textContent = '';
            if ( ! total ) {
                var tr = document.createElement( 'tr' );
                tr.className = 'tbt-fc-gl-empty';
                var td = document.createElement( 'td' );
                td.colSpan = dict ? 3 : 2;
                td.appendChild( document.createTextNode( format( t.noMatch || 'No expressions match “%s”.', query.trim() ) + ' ' ) );
                var b = document.createElement( 'button' );
                b.type = 'button';
                b.className = 'tbt-fc-gl-linkbtn';
                b.textContent = t.clear || 'Clear search';
                b.addEventListener( 'click', clearQuery );
                td.appendChild( b );
                tr.appendChild( td );
                tbody.appendChild( tr );
            }
            shown.forEach( function ( idx ) {
                var row = document.createElement( 'tr' );
                var a = document.createElement( 'td' );
                var c = document.createElement( 'td' );
                highlight( a, data[ idx ][ 0 ], n );
                highlight( c, data[ idx ][ 1 ], n );
                row.appendChild( a );
                row.appendChild( c );
                if ( dict ) {
                    row.appendChild( actCell( idx ) );
                }
                tbody.appendChild( row );
            } );

            count.textContent = query.trim()
                ? format( t.countOf || '%1$d of %2$d expressions', total, data.length )
                : format( t.count || '%d expressions', data.length );

            var from = total ? ( showAll ? 1 : page * PER + 1 ) : 0;
            var to = showAll ? total : Math.min( total, page * PER + PER );
            var paged = total > PER;
            pager.hidden = ! paged;
            prev.hidden = next.hidden = range.hidden = showAll || ! paged;
            range.textContent = total ? format( t.range || '%1$d–%2$d of %3$d', from, to, total ) : '';
            prev.disabled = 0 === page;
            next.disabled = page >= pages - 1;
            var left = total - to;
            next.textContent = format( t.next || 'Next %d', left > 0 && left < PER ? left : PER );
            allBtn.hidden = ! paged;
            allBtn.textContent = showAll ? ( t.showPaged || 'Show 10 at a time' ) : format( t.showAll || 'Show all %d', total );

            Array.prototype.forEach.call( heads, function ( th ) {
                var col = parseInt( th.getAttribute( 'data-col' ), 10 );
                var on = col === sortCol;
                th.setAttribute( 'aria-sort', on ? ( sortDir > 0 ? 'ascending' : 'descending' ) : 'none' );
                var arrow = th.querySelector( '.tbt-fc-gl-arrow' );
                if ( arrow ) {
                    arrow.textContent = on ? ( sortDir > 0 ? '▲' : '▼' ) : '↕';
                }
            } );
        }

        // The My Dictionary cell: empty until the lookup answers.
        function actCell( idx ) {
            var td = document.createElement( 'td' );
            td.className = 'tbt-fc-gl-act';
            if ( ! dict.ready ) {
                return td;
            }
            var term = data[ idx ][ 0 ];
            if ( dict.have[ idx ] ) {
                var inDict = el( 'span', 'tbt-fc-gl-in', t.dictIn || '✓ In my dictionary' );
                inDict.title = format( t.dictInTitle || 'In %s', dict.have[ idx ] );
                inDict.tabIndex = -1;
                inDict.setAttribute( 'data-idx', String( idx ) );
                td.appendChild( inDict );
                return td;
            }
            var b = el( 'button', 'tbt-fc-gl-add' );
            b.type = 'button';
            b.setAttribute( 'aria-label', format( t.dictAddLabel || 'Add %s to My Dictionary', term ) );
            b.appendChild( plusIcon() );
            b.appendChild( document.createTextNode( t.dictAdd || 'My Dictionary' ) );
            b.addEventListener( 'click', function () {
                openModal( inst, idx, b );
            } );
            td.appendChild( b );
            return td;
        }

        function clearQuery() {
            query = '';
            input.value = '';
            clearBtn.hidden = true;
            page = 0;
            render();
            input.focus();
        }

        input.addEventListener( 'input', function () {
            query = input.value;
            clearBtn.hidden = ! query;
            page = 0;
            render();
        } );
        input.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key || 'Esc' === e.key ) {
                e.preventDefault();
                clearQuery();
            }
        } );
        clearBtn.addEventListener( 'click', clearQuery );
        prev.addEventListener( 'click', function () {
            if ( page > 0 ) {
                page--;
                render();
            }
        } );
        next.addEventListener( 'click', function () {
            page++;
            render();
        } );
        allBtn.addEventListener( 'click', function () {
            showAll = ! showAll;
            page = 0;
            render();
        } );
        Array.prototype.forEach.call( heads, function ( th ) {
            var btn = th.querySelector( 'button' );
            if ( ! btn ) {
                return;
            }
            btn.addEventListener( 'click', function () {
                var col = parseInt( th.getAttribute( 'data-col' ), 10 );
                if ( sortCol === col ) {
                    if ( sortDir > 0 ) {
                        sortDir = -1;
                    } else {
                        sortCol = null;
                        sortDir = 1;
                    }
                } else {
                    sortCol = col;
                    sortDir = 1;
                }
                page = 0;
                render();
            } );
        } );

        inst.render = render;
        render();
        if ( dict ) {
            instances.push( inst );
            lookup( inst );
        }
    }

    function boot() {
        Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-gl[data-rows]' ), init );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
}() );
